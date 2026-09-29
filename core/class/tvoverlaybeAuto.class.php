<?php
/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/*
 * Indicateurs automatiques : la logique, sans Jeedom ni réseau.
 *
 * Un indicateur automatique est décrit dans l'équipement (configuration
 * « auto_fixed ») : quand il est visible, quel texte, quelle icône. Le plugin
 * le recalcule à chaque changement d'une commande citée et l'envoie à la TV,
 * sans scénario. Ce fichier ne fait que les calculs : lire la configuration,
 * évaluer les conditions, mettre en forme le texte, et décider — à partir de
 * ce qui a été envoyé la dernière fois — s'il faut envoyer, retirer ou ne
 * rien faire. Les lectures de commandes arrivent par une fonction passée en
 * paramètre, et l'heure en paramètre aussi : tout se teste hors ligne
 * (tests/run.php), sans doublure du coeur.
 *
 * L'état retenu pour chaque indicateur (dans le cache de l'équipement) :
 *   shown    true : envoyé et, à notre connaissance, à l'écran ;
 *            false : retiré (ou jamais montré) ;
 *            null : inconnu — Jeedom a redémarré, TvOverlay a été relancée,
 *            la TV s'est rallumée : l'écran a pu perdre l'indicateur ;
 *   sig      l'empreinte de ce qui a été envoyé (texte, icône, couleurs…) ;
 *   sent_at  l'heure de cet envoi, pour le renouveler avant expiration ;
 *   snoozed  l'empreinte de ce qu'on a retiré à la main (« Retirer un
 *            indicateur », « Retirer tous les indicateurs », croix du
 *            widget), ou null.
 */
class tvoverlaybeAuto {

    /* Douze heures, comme l'automation Home Assistant qu'il remplace. Assez
     * long pour ne renvoyer l'indicateur que deux fois par jour quand rien
     * ne change ; assez court pour qu'un indicateur ne reste pas affiché à
     * tort des jours durant si Jeedom s'arrête (panne, sauvegarde qui
     * échoue) : « lampe allumée » alors qu'elle est éteinte depuis hier
     * serait pire que pas d'indicateur du tout. */
    const DEFAULT_EXPIRATION = '12h';

    /* En dessous de deux minutes, le cron de la minute ne garantit plus le
     * renouvellement avant l'expiration : l'indicateur clignoterait. */
    const MIN_EXPIRATION = 120;

    const OPERATORS = array('==', '!=', '>', '>=', '<', '<=');
    const SHAPES = array('circle', 'rounded', 'rectangular');
    const COLORS = array('iconColor', 'messageColor', 'borderColor', 'backgroundColor');

    /* ============================================================ LECTURE */

    /* Une durée « 1y2w3d4h5m6s » ou des secondes → secondes, ou null.
     * Pas de date epoch ici, contrairement à l'Indicateur (JSON) : une date
     * fixe ne se renouvelle pas, et l'indicateur s'éteindrait pour de bon. */
    public static function seconds($_expiration) {
        $text = strtolower(trim((string) $_expiration));
        if ($text === '') {
            return null;
        }
        if (preg_match('/^\d+$/', $text)) {
            $value = (int) $text;
            return ($value > 0 && $value < 1000000000) ? $value : null;
        }
        if (!preg_match('/^(?:(\d+)y)?(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $text, $m)) {
            return null;
        }
        $units = array(1 => 31536000, 2 => 604800, 3 => 86400, 4 => 3600, 5 => 60, 6 => 1);
        $seconds = 0;
        foreach ($units as $index => $unit) {
            $seconds += isset($m[$index]) && $m[$index] !== '' ? (int) $m[$index] * $unit : 0;
        }
        return $seconds > 0 ? $seconds : null;
    }

    /* Une référence de commande « #123# » (ou « 123 ») → 123, sinon 0. Le
     * coeur convertit « #[Salon][Lampe][Etat]# » en « #123# » à
     * l'enregistrement de la page ; par l'API JSON-RPC, on l'écrit ainsi. */
    public static function cmdId($_ref) {
        $text = trim((string) $_ref);
        return preg_match('/^#?(\d+)#?$/', $text, $m) ? (int) $m[1] : 0;
    }

    private static function text($_indicator, $_key, $_default = '') {
        return isset($_indicator[$_key]) && !is_array($_indicator[$_key]) ? trim((string) $_indicator[$_key]) : $_default;
    }

    /* Un indicateur tel que saisi → un indicateur complet, chaque champ à
     * une valeur connue. Rien n'est refusé ici (voir errors()) : la page et
     * le moteur lisent la même forme, quoi qu'on ait écrit. */
    public static function normalize($_indicator) {
        $i = is_array($_indicator) ? $_indicator : array();
        $conditions = array();
        foreach ((isset($i['conditions']) && is_array($i['conditions'])) ? $i['conditions'] : array() as $condition) {
            if (!is_array($condition)) {
                continue;
            }
            $operator = self::text($condition, 'operator', '==');
            $conditions[] = array(
                'cmd' => self::text($condition, 'cmd'),
                'operator' => in_array($operator, self::OPERATORS, true) ? $operator : '==',
                'value' => self::text($condition, 'value'),
            );
        }
        $decimals = self::text($i, 'decimals');
        $normalized = array(
            /* Désactivé seulement si on l'a dit : 0, « 0 » ou false. */
            'enable' => (isset($i['enable']) && in_array($i['enable'], array(0, '0', false), true)) ? 0 : 1,
            'id' => self::text($i, 'id'),
            'name' => self::text($i, 'name'),
            'visibility' => self::text($i, 'visibility') === 'conditions' ? 'conditions' : 'always',
            'combine' => self::text($i, 'combine') === 'all' ? 'all' : 'any',
            'conditions' => $conditions,
            'text_mode' => in_array(self::text($i, 'text_mode'), array('fixed', 'cmd'), true) ? self::text($i, 'text_mode') : 'none',
            'text' => isset($i['text']) && !is_array($i['text']) ? (string) $i['text'] : '',
            'text_cmd' => self::text($i, 'text_cmd'),
            'decimals' => ($decimals !== '' && ctype_digit($decimals)) ? (string) min(6, (int) $decimals) : '',
            'suffix' => isset($i['suffix']) && !is_array($i['suffix']) ? (string) $i['suffix'] : '',
            'icon_mode' => self::text($i, 'icon_mode') === 'cmd' ? 'cmd' : 'fixed',
            'icon' => self::text($i, 'icon'),
            'icon_cmd' => self::text($i, 'icon_cmd'),
            'shape' => in_array(self::text($i, 'shape'), self::SHAPES, true) ? self::text($i, 'shape') : '',
            'expiration' => self::seconds(self::text($i, 'expiration')) !== null ? strtolower(self::text($i, 'expiration')) : self::DEFAULT_EXPIRATION,
        );
        foreach (self::COLORS as $color) {
            $normalized[$color] = self::text($i, $color);
        }
        return $normalized;
    }

    /* La liste de l'équipement, normalisée. Une liste JSON en texte est
     * acceptée aussi : c'est ce qu'on écrit le plus facilement à la main. */
    public static function normalizeAll($_list) {
        if (is_string($_list)) {
            $_list = json_decode($_list, true);
        }
        $list = array();
        foreach (is_array($_list) ? $_list : array() as $indicator) {
            if (is_array($indicator)) {
                $list[] = self::normalize($indicator);
            }
        }
        return $list;
    }

    /* Ce qui empêcherait le moteur de faire son travail, en clair. Les
     * indicateurs désactivés sont contrôlés aussi : on les réactive sans y
     * repenser. */
    public static function errors($_list) {
        $errors = array();
        $seen = array();
        foreach (self::normalizeAll($_list) as $index => $i) {
            $label = 'Indicateur n°' . ($index + 1) . ($i['name'] !== '' ? ' (' . $i['name'] . ')' : '');
            if ($i['id'] === '') {
                /* La règle du plugin : sans id, TvOverlay en tire un au
                 * hasard et l'indicateur ne se retire plus. */
                $errors[] = $label . ' : id obligatoire, sans quoi l\'indicateur ne pourrait plus être retiré.';
                continue;
            }
            if (isset($seen[$i['id']])) {
                $errors[] = $label . ' : l\'id « ' . $i['id'] . ' » est déjà pris par un autre indicateur automatique.';
            }
            $seen[$i['id']] = true;
            if ($i['visibility'] === 'conditions') {
                if (count($i['conditions']) === 0) {
                    $errors[] = $label . ' : « Visible si » sans aucune condition.';
                }
                foreach ($i['conditions'] as $condition) {
                    if (self::cmdId($condition['cmd']) === 0) {
                        $errors[] = $label . ' : une condition ne désigne pas de commande.';
                        break;
                    }
                }
            }
            if ($i['text_mode'] === 'cmd' && self::cmdId($i['text_cmd']) === 0) {
                $errors[] = $label . ' : texte issu d\'une commande, mais aucune commande choisie.';
            }
            if ($i['icon_mode'] === 'cmd' && self::cmdId($i['icon_cmd']) === 0) {
                $errors[] = $label . ' : icône issue d\'une commande, mais aucune commande choisie.';
            }
        }
        return $errors;
    }

    /* Les commandes dont un changement peut changer l'indicateur : c'est ce
     * qu'écoute le listener. Seules celles réellement utilisées comptent :
     * une commande de texte laissée là quand le texte est « fixe » ne
     * réveille rien. */
    public static function cmdIds($_indicator) {
        $i = self::normalize($_indicator);
        $ids = array();
        if ($i['visibility'] === 'conditions') {
            foreach ($i['conditions'] as $condition) {
                $ids[] = self::cmdId($condition['cmd']);
            }
        }
        if ($i['text_mode'] === 'cmd') {
            $ids[] = self::cmdId($i['text_cmd']);
        }
        if ($i['icon_mode'] === 'cmd') {
            $ids[] = self::cmdId($i['icon_cmd']);
        }
        return array_values(array_unique(array_filter($ids)));
    }

    /* ============================================================ CALCULS */

    /* Une condition. null : la commande n'existe pas ou n'a pas de valeur —
     * c'est faux, même pour « != » : une lampe supprimée ne doit pas
     * allumer l'ampoule.
     *
     * Deux nombres se comparent en nombres (« 1.0 == 1 », « 9 < 10 ») ; sinon
     * == et != comparent le texte sans tenir compte de la casse (« on » ou
     * « ON » selon les modules), et les autres opérateurs sont faux : « abc >
     * 3 » n'a pas de sens, et un faux vrai afficherait l'indicateur. */
    public static function compare($_actual, $_operator, $_expected) {
        if ($_actual === null || is_array($_actual)) {
            return false;
        }
        $a = trim((string) $_actual);
        $e = trim((string) $_expected);
        if ($a === '') {
            return false;
        }
        $numeric = is_numeric($a) && is_numeric($e);
        switch ($_operator) {
            case '==':
                return $numeric ? (float) $a == (float) $e : strcasecmp($a, $e) === 0;
            case '!=':
                return $numeric ? (float) $a != (float) $e : strcasecmp($a, $e) !== 0;
            case '>':
                return $numeric && (float) $a > (float) $e;
            case '>=':
                return $numeric && (float) $a >= (float) $e;
            case '<':
                return $numeric && (float) $a < (float) $e;
            case '<=':
                return $numeric && (float) $a <= (float) $e;
        }
        return false;
    }

    /* $_valueOf : fonction (id de commande) → valeur, ou null. */
    public static function isVisible($_indicator, $_valueOf) {
        $i = self::normalize($_indicator);
        if ($i['visibility'] !== 'conditions') {
            return true;
        }
        /* « Visible si » sans condition : jamais visible, et errors() le dit.
         * Le contraire afficherait en permanence un indicateur qu'on a
         * voulu conditionnel. */
        if (count($i['conditions']) === 0) {
            return false;
        }
        foreach ($i['conditions'] as $condition) {
            $id = self::cmdId($condition['cmd']);
            $ok = $id > 0 && self::compare(call_user_func($_valueOf, $id), $condition['operator'], $condition['value']);
            if ($i['combine'] === 'any' && $ok) {
                return true;
            }
            if ($i['combine'] === 'all' && !$ok) {
                return false;
            }
        }
        return $i['combine'] === 'all';
    }

    /* La valeur d'une commande → le texte affiché. Arrondie si un nombre de
     * décimales est donné et que la valeur est un nombre (une température
     * 21.6 → « 22 ») ; la virgule décimale à la française par défaut. Un
     * « -0 » (-0.4 arrondi) devient « 0 » : « -0° » surprendrait. Le suffixe
     * n'est ajouté qu'à une valeur : pas de « ° » tout seul quand la
     * météo n'a encore rien publié. */
    public static function formatText($_value, $_decimals = '', $_suffix = '', $_separator = ',') {
        if ($_value === null || is_array($_value)) {
            return '';
        }
        $text = trim((string) $_value);
        if ($text === '') {
            return '';
        }
        if ((string) $_decimals !== '' && is_numeric($text)) {
            $decimals = max(0, (int) $_decimals);
            $rounded = round((float) $text, $decimals);
            if ($rounded == 0) {
                $rounded = 0.0;
            }
            $text = number_format($rounded, $decimals, $_separator, '');
        }
        return $text . (string) $_suffix;
    }

    /* Un nom d'icône publié sans préfixe (« weather-rainy ») est un nom
     * Material Design : TvOverlay veut « mdi:weather-rainy ». Une adresse ou
     * un base64 passent tels quels. */
    public static function iconName($_value) {
        $icon = trim((string) $_value);
        return preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $icon) ? 'mdi:' . $icon : $icon;
    }

    /* Ce qu'il faut envoyer à /notify_fixed, sans l'expiration, ou null si
     * l'indicateur ne doit pas être visible. L'expiration est à part : elle
     * n'est pas « ce qui est affiché », et la compter dans l'empreinte
     * ferait renvoyer l'indicateur à chaque calcul. */
    public static function body($_indicator, $_valueOf, $_separator = ',') {
        $i = self::normalize($_indicator);
        if (!self::isVisible($i, $_valueOf)) {
            return null;
        }
        $body = array('id' => $i['id']);
        $message = '';
        if ($i['text_mode'] === 'fixed') {
            $message = $i['text'];
        } elseif ($i['text_mode'] === 'cmd') {
            $id = self::cmdId($i['text_cmd']);
            $message = $id > 0 ? self::formatText(call_user_func($_valueOf, $id), $i['decimals'], $i['suffix'], $_separator) : '';
        }
        if ($message !== '') {
            $body['message'] = $message;
        }
        $icon = '';
        if ($i['icon_mode'] === 'cmd') {
            $id = self::cmdId($i['icon_cmd']);
            $value = $id > 0 ? call_user_func($_valueOf, $id) : null;
            $icon = ($value === null || is_array($value)) ? '' : self::iconName($value);
        }
        /* L'icône fixe sert aussi de repli quand la commande n'a encore
         * rien publié : mieux vaut un nuage générique qu'une pastille vide. */
        if ($icon === '') {
            $icon = $i['icon'];
        }
        if ($icon !== '') {
            $body['icon'] = $icon;
        }
        foreach (self::COLORS as $color) {
            if ($i[$color] !== '') {
                $body[$color] = $i[$color];
            }
        }
        if ($i['shape'] !== '') {
            $body['shape'] = $i['shape'];
        }
        return $body;
    }

    public static function signature($_body) {
        if ($_body === null) {
            return '';
        }
        ksort($_body);
        return md5(json_encode($_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /* =========================================================== DÉCISION */

    public static function freshState() {
        return array('shown' => null, 'sig' => '', 'sent_at' => 0, 'snoozed' => null);
    }

    /* Le renouvellement : une heure avant l'expiration, ou à mi-chemin pour
     * une expiration courte. Assez tôt pour qu'un cron manqué ou une TV qui
     * met du temps à répondre ne laissent pas l'indicateur s'éteindre ;
     * assez tard pour ne renvoyer que deux fois par jour à 12 h. */
    public static function refreshDue($_sentAt, $_expiration, $_now) {
        $seconds = self::seconds($_expiration);
        if ($seconds === null) {
            $seconds = self::seconds(self::DEFAULT_EXPIRATION);
        }
        $seconds = max(self::MIN_EXPIRATION, $seconds);
        $margin = min((int) floor($seconds / 2), 3600);
        return $_now >= (int) $_sentAt + $seconds - $margin;
    }

    /*
     * Que faire pour un indicateur, vu ce qui doit être affiché ($_body, ou
     * null s'il doit être caché) et ce qui l'a été ($_state) ?
     *
     * Rend array('action' => 'send' | 'remove' | 'none', 'state' => …) ;
     * 'state' est l'état à retenir SI l'action réussit (pour 'none', tout de
     * suite). On n'envoie que si ce qui est affiché change — apparition,
     * texte, icône, couleur —, si l'écran a pu le perdre (shown null), ou
     * pour le renouveler avant son expiration : une température relue à
     * l'identique toutes les dix minutes ne fait pas de requête.
     */
    public static function decide($_body, $_state, $_expiration, $_now) {
        $state = array_merge(self::freshState(), is_array($_state) ? $_state : array());
        if ($_body === null) {
            /* Caché : un retrait manuel a atteint son but, on l'oublie — le
             * prochain affichage sera de nouveau montré. */
            $state['snoozed'] = null;
            if ($state['shown'] === false) {
                return array('action' => 'none', 'state' => $state);
            }
            /* shown null : peut-être encore à l'écran (Jeedom a redémarré
             * entre-temps) ; un retrait de trop ne coûte rien, un indicateur
             * « lampe allumée » oublié à l'écran, si. */
            $state['shown'] = false;
            $state['sig'] = '';
            return array('action' => 'remove', 'state' => $state);
        }
        $sig = self::signature($_body);
        if ($state['snoozed'] !== null) {
            if ($state['snoozed'] === $sig) {
                /* Retiré à la main, et rien n'a changé depuis : il reste
                 * retiré, même après une relance de TvOverlay. */
                $state['shown'] = false;
                return array('action' => 'none', 'state' => $state);
            }
            $state['snoozed'] = null;
        }
        if ($state['shown'] === true && $state['sig'] === $sig && !self::refreshDue($state['sent_at'], $_expiration, $_now)) {
            return array('action' => 'none', 'state' => $state);
        }
        $state['shown'] = true;
        $state['sig'] = $sig;
        $state['sent_at'] = (int) $_now;
        return array('action' => 'send', 'state' => $state);
    }

    /* Retrait à la main : on retient ce qui était affiché, pour ne pas le
     * remettre tant que rien ne change. */
    public static function snooze($_state) {
        $state = array_merge(self::freshState(), is_array($_state) ? $_state : array());
        $state['snoozed'] = $state['sig'];
        $state['shown'] = false;
        return $state;
    }

    /* L'écran a pu perdre les indicateurs (redémarrage de Jeedom, relance de
     * TvOverlay, TV rallumée) : ceux qu'on croyait affichés redeviennent
     * « inconnus », et le prochain calcul les renverra. Les retirés restent
     * retirés. */
    public static function lost($_states) {
        $states = is_array($_states) ? $_states : array();
        foreach ($states as $id => $state) {
            if (is_array($state) && isset($state['shown']) && $state['shown'] === true) {
                $states[$id]['shown'] = null;
            }
        }
        return $states;
    }
}
