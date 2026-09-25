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

require_once __DIR__ . '/../../../../core/php/core.inc.php';

/*
 * Notifications sur les téléviseurs Android TV / Google TV, par l'appli
 * TvOverlay (com.tabdeveloper.tvoverlay, Play Store).
 *
 * TvOverlay affiche par-dessus l'image, sans interrompre ce qui passe :
 *   - des notifications : titre, message, icônes, image, et même une vidéo
 *     (flux RTSP d'une caméra) ;
 *   - des indicateurs fixes, dans un coin, jusqu'à expiration ou retrait ;
 *   - une horloge.
 * Elle expose une API HTTP JSON sur le port 5001 (POST /notify,
 * /notify_fixed, /set/overlay, /set/notifications, /set/settings ; GET
 * /get). Tout se fait par elle, en local.
 *
 * Android arrête TvOverlay de temps à autre. Le plugin Google TV (googletvbe)
 * sait la relancer par la télécommande : quand il est installé et pilote la
 * même TV, une notification qui trouve TvOverlay arrêtée lui demande de la
 * relancer, puis repart. Sans lui, la notification échoue, et l'info « En
 * ligne » le dit.
 *
 * Un indicateur fixe n'est retirable que par son identifiant : TvOverlay en
 * tire un au hasard quand on n'en donne pas, ne le renvoie pas, et n'offre
 * aucun moyen de lister les indicateurs affichés (vérifié sur la version
 * 10040). Le plugin exige donc un id, et tient la liste de ceux qu'il a
 * affichés, avec leur expiration, pour pouvoir tous les retirer.
 *
 * Une notification ordinaire se retire en la renvoyant sous le même id avec
 * une durée d'une seconde, mais TvOverlay ignore une mise à jour sans texte
 * (vérifié : { id, duration } seuls laissent la notification jusqu'à son
 * terme). Le plugin retient donc le contenu des dernières notifications
 * envoyées avec un id, et le renvoie tel quel pour les retirer.
 *
 * L'identifiant logique d'un équipement est l'identifiant de l'installation
 * de TvOverlay (status.id), stable quand l'adresse IP change.
 */
class tvoverlaybe extends eqLogic {

    const DEFAULT_PORT = 5001;
    const TIMEOUT = 3;

    const CORNERS = array(
        'top_end' => 'En haut à droite',
        'top_start' => 'En haut à gauche',
        'bottom_end' => 'En bas à droite',
        'bottom_start' => 'En bas à gauche',
    );

    /* Les commandes, dans leur ordre d'affichage. Seules les absentes sont
     * créées : un nom ou une icône changés par l'utilisateur sont gardés. */
    const COMMANDS = array(
        'online'          => array('name' => 'En ligne', 'type' => 'info', 'subType' => 'binary'),
        'screen'          => array('name' => 'Écran allumé', 'type' => 'info', 'subType' => 'binary'),
        'notifications'   => array('name' => 'Notifications actives', 'type' => 'info', 'subType' => 'binary'),
        'fixed'           => array('name' => 'Indicateurs actifs', 'type' => 'info', 'subType' => 'binary'),
        'fixed_list'      => array('name' => 'Indicateurs affichés', 'type' => 'info', 'subType' => 'string'),
        'clock'           => array('name' => 'Horloge', 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'minValue' => 0, 'maxValue' => 95),
        'background'      => array('name' => 'Fond', 'type' => 'info', 'subType' => 'numeric', 'unite' => '%', 'minValue' => 0, 'maxValue' => 95),
        'duration'        => array('name' => 'Durée des notifications', 'type' => 'info', 'subType' => 'numeric', 'unite' => 's', 'minValue' => 1, 'maxValue' => 300),
        'corner'          => array('name' => 'Coin de l’overlay', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'permission'      => array('name' => 'Autorisation d’affichage', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'battery'         => array('name' => 'Optimisation de batterie', 'type' => 'info', 'subType' => 'string', 'visible' => 0),
        'version'         => array('name' => 'Version de TvOverlay', 'type' => 'info', 'subType' => 'string', 'visible' => 0),

        'refresh'         => array('name' => 'Rafraîchir', 'type' => 'action', 'subType' => 'other'),
        'notify'          => array('name' => 'Notifier', 'type' => 'action', 'subType' => 'message', 'icon' => 'fas fa-comment-alt'),
        'notify_json'     => array('name' => 'Notifier (JSON)', 'type' => 'action', 'subType' => 'message', 'visible' => 0,
                                   'display' => array('title_disable' => 1, 'message_placeholder' => '{"id":"sonnette","title":"On sonne","duration":30}')),
        'dismiss'         => array('name' => 'Retirer une notification', 'type' => 'action', 'subType' => 'message', 'visible' => 0,
                                   'display' => array('title_disable' => 1, 'message_placeholder' => 'id')),
        'fixed_json'      => array('name' => 'Indicateur (JSON)', 'type' => 'action', 'subType' => 'message', 'visible' => 0,
                                   'display' => array('title_disable' => 1, 'message_placeholder' => '{"id":"lampe","icon":"mdi:lightbulb"}')),
        'fixed_remove'    => array('name' => 'Retirer un indicateur', 'type' => 'action', 'subType' => 'message', 'visible' => 0,
                                   'display' => array('title_disable' => 1, 'message_placeholder' => 'id')),
        'fixed_clear'     => array('name' => 'Retirer tous les indicateurs', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'notifications_on'  => array('name' => 'Activer les notifications', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'notifications_off' => array('name' => 'Suspendre les notifications', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'fixed_on'        => array('name' => 'Afficher les indicateurs', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'fixed_off'       => array('name' => 'Masquer les indicateurs', 'type' => 'action', 'subType' => 'other', 'visible' => 0),
        'clock_set'       => array('name' => 'Régler l’horloge', 'type' => 'action', 'subType' => 'slider', 'value' => 'clock', 'minValue' => 0, 'maxValue' => 95),
        'background_set'  => array('name' => 'Régler le fond', 'type' => 'action', 'subType' => 'slider', 'value' => 'background', 'minValue' => 0, 'maxValue' => 95, 'visible' => 0),
        'duration_set'    => array('name' => 'Régler la durée', 'type' => 'action', 'subType' => 'slider', 'value' => 'duration', 'minValue' => 1, 'maxValue' => 300, 'visible' => 0),
        'corner_set'      => array('name' => 'Choisir le coin de l’overlay', 'type' => 'action', 'subType' => 'select', 'value' => 'corner', 'visible' => 0),
    );

    /* ================================================================ CRON */

    /* L'état de TvOverlay, relu chaque minute. Les TV sont interrogées en
     * parallèle : une TV éteinte ne fait pas attendre les autres. */
    public static function cron() {
        $eqLogics = array_values(array_filter(self::byType(__CLASS__, true), function ($e) { return $e->isConfigured(); }));
        if (count($eqLogics) === 0) {
            return;
        }
        $multi = curl_multi_init();
        $handles = array();
        foreach ($eqLogics as $index => $eqLogic) {
            $handles[$index] = $eqLogic->curlHandle('/get', null, 2);
            curl_multi_add_handle($multi, $handles[$index]);
        }
        do {
            $status = curl_multi_exec($multi, $active);
            if ($active) {
                curl_multi_select($multi, 0.2);
            }
        } while ($active && $status == CURLM_OK);
        foreach ($eqLogics as $index => $eqLogic) {
            $body = curl_multi_getcontent($handles[$index]);
            $code = (int) curl_getinfo($handles[$index], CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($multi, $handles[$index]);
            curl_close($handles[$index]);
            $reply = ($code === 200) ? json_decode((string) $body, true) : null;
            try {
                $eqLogic->publishFixed();
                if (is_array($reply) && !empty($reply['success'])) {
                    $eqLogic->ingest($reply['result']);
                } else {
                    $eqLogic->checkAndUpdateCmd('online', 0);
                }
            } catch (Throwable $e) {
                log::add(__CLASS__, 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
            }
        }
        curl_multi_close($multi);
    }

    /* ======================================================== CYCLE DE VIE */

    /* Aucune exception ici : le coeur crée l'équipement avec son seul nom. */
    public function preSave() {
        if ($this->getId() == '') {
            $this->setIsEnable(1);
            $this->setIsVisible(1);
        }
        $this->setConfiguration('ip', trim((string) $this->getConfiguration('ip', '')));
        if ($this->getConfiguration('relaunch', '') === '') {
            $this->setConfiguration('relaunch', 1);
        }
    }

    public function postSave() {
        $this->createCommands();
    }

    public function postRemove() {
        config::remove('fixed::' . $this->getId(), __CLASS__);
    }

    public function isConfigured() {
        return $this->getConfiguration('ip', '') !== '';
    }

    public function port() {
        $port = (int) $this->getConfiguration('port', self::DEFAULT_PORT);
        return ($port > 0 && $port < 65536) ? $port : self::DEFAULT_PORT;
    }

    public function createCommands() {
        $order = 0;
        foreach (self::COMMANDS as $logicalId => $def) {
            $order++;
            $cmd = $this->getCmd(null, $logicalId);
            if (is_object($cmd)) {
                /* Noms abîmés par le coeur avant la 0.1.1 : il retire « ' »
                 * (« Régler lhorloge »). Seul un nom resté tel quel est
                 * corrigé, jamais un nom choisi par l'utilisateur. */
                $legacy = cleanComponanteName(str_replace('’', "'", $def['name']));
                $changed = false;
                if ($legacy !== $def['name'] && $cmd->getName() === $legacy) {
                    $cmd->setName($def['name']);
                    $changed = true;
                }
                /* Réglages d'affichage apparus après la création : posés
                 * s'ils manquent, jamais écrasés. */
                foreach (isset($def['display']) ? $def['display'] : array() as $key => $value) {
                    if ($cmd->getDisplay($key, '') === '') {
                        $cmd->setDisplay($key, $value);
                        $changed = true;
                    }
                }
                if ($changed) {
                    try {
                        $cmd->save();
                    } catch (Throwable $e) {
                        log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . $e->getMessage());
                    }
                }
                continue;
            }
            try {
                $this->createCommand($logicalId, $def, $order);
            } catch (Throwable $e) {
                /* Un nom déjà pris par une commande renommée : les autres
                 * commandes sont créées quand même. */
                log::add(__CLASS__, 'error', $this->getHumanName() . ' : ' . __('commande', __FILE__) . ' ' . $logicalId . ' : ' . $e->getMessage());
            }
        }
    }

    private function createCommand($_logicalId, $_def, $_order) {
        $cmd = new tvoverlaybeCmd();
        $cmd->setEqLogic_id($this->getId());
        $cmd->setLogicalId($_logicalId);
        $cmd->setName(__($_def['name'], __FILE__));
        $cmd->setType($_def['type']);
        $cmd->setSubType($_def['subType']);
        $cmd->setOrder($_order);
        $cmd->setIsVisible(isset($_def['visible']) ? $_def['visible'] : 1);
        if (isset($_def['unite'])) {
            $cmd->setUnite($_def['unite']);
        }
        if (isset($_def['minValue'])) {
            $cmd->setConfiguration('minValue', $_def['minValue']);
            $cmd->setConfiguration('maxValue', $_def['maxValue']);
        }
        if (isset($_def['icon'])) {
            $cmd->setDisplay('icon', '<i class="' . $_def['icon'] . '"></i>');
        }
        foreach (isset($_def['display']) ? $_def['display'] : array() as $key => $value) {
            $cmd->setDisplay($key, $value);
        }
        if ($_logicalId === 'corner_set') {
            $choices = array();
            foreach (self::CORNERS as $key => $label) {
                $choices[] = $key . '|' . $label;
            }
            $cmd->setConfiguration('listValue', implode(';', $choices));
        }
        if (isset($_def['value'])) {
            $info = $this->getCmd('info', $_def['value']);
            if (is_object($info)) {
                $cmd->setValue($info->getId());
            }
        }
        $cmd->save();
    }

    /* ================================================================ API */

    public function curlHandle($_path, $_body = null, $_timeout = self::TIMEOUT) {
        $ch = curl_init('http://' . $this->getConfiguration('ip') . ':' . $this->port() . $_path);
        $options = array(
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $_timeout,
            CURLOPT_TIMEOUT => $_timeout,
            CURLOPT_PROXY => '',
        );
        if ($_body !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($_body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_HTTPHEADER] = array('Content-Type: application/json');
        }
        curl_setopt_array($ch, $options);
        return $ch;
    }

    /* Un appel à TvOverlay. tvoverlaybeDown : l'appli ne répond pas (à
     * relancer) ; Exception : elle répond mais refuse (inutile de relancer). */
    public function request($_path, $_body = null, $_timeout = self::TIMEOUT) {
        $ch = $this->curlHandle($_path, $_body, $_timeout);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code === 0) {
            throw new tvoverlaybeDown(__('TvOverlay ne répond pas', __FILE__) . ($error !== '' ? ' (' . $error . ')' : ''));
        }
        $reply = json_decode((string) $body, true);
        if ($code !== 200 || !is_array($reply) || empty($reply['success'])) {
            throw new Exception(__('TvOverlay refuse :', __FILE__) . ' ' . (is_array($reply) && isset($reply['message']) ? $reply['message'] : 'HTTP ' . $code));
        }
        return $reply;
    }

    /* Le plugin Google TV est-il installé et actif ? plugin::byId() lève
     * une exception pour un plugin absent. */
    public static function googleTvAvailable() {
        try {
            $plugin = plugin::byId('googletvbe');
            return is_object($plugin) && $plugin->isActive() && class_exists('googletvbe');
        } catch (Throwable $e) {
            return false;
        }
    }

    /* L'équipement Google TV qui pilote la même TV, s'il y en a un. */
    public function googleTv() {
        if (!self::googleTvAvailable()) {
            return null;
        }
        $chosen = (string) $this->getConfiguration('googletv', '');
        if ($chosen !== '') {
            $eqLogic = eqLogic::byId((int) $chosen);
            return (is_object($eqLogic) && $eqLogic->getEqType_name() === 'googletvbe' && $eqLogic->getIsEnable()) ? $eqLogic : null;
        }
        foreach (eqLogic::byType('googletvbe', true) as $eqLogic) {
            if ($eqLogic->getConfiguration('ip') === $this->getConfiguration('ip')) {
                return $eqLogic;
            }
        }
        return null;
    }

    /* Envoie ; si TvOverlay est arrêtée, la fait relancer par le plugin
     * Google TV, puis renvoie. */
    public function send($_path, $_body) {
        try {
            $reply = $this->request($_path, $_body);
            $this->checkAndUpdateCmd('online', 1);
            return $reply;
        } catch (tvoverlaybeDown $e) {
            $this->checkAndUpdateCmd('online', 0);
            $googleTv = ((int) $this->getConfiguration('relaunch', 1) === 1) ? $this->googleTv() : null;
            if ($googleTv === null || !method_exists($googleTv, 'overlayRelaunch')) {
                throw $e;
            }
            /* TV en veille : la relance ouvrirait la fiche Play Store et
             * pourrait rallumer l'écran, en pleine nuit peut-être. Une
             * notification sur un écran éteint ne se verrait de toute façon
             * pas. */
            $power = $googleTv->getCmd('info', 'power');
            $state = is_object($power) ? (string) $power->execCmd() : '';
            if ($state === '') {
                throw new tvoverlaybeDown(__('TvOverlay ne répond pas et l\'état de la TV est inconnu : pas de relance.', __FILE__));
            }
            if ((int) $state !== 1) {
                throw new tvoverlaybeDown(__('TvOverlay ne répond pas et la TV est en veille : notification non affichée.', __FILE__));
            }
            log::add(__CLASS__, 'info', $this->getHumanName() . ' : ' . __('TvOverlay arrêtée, relance par', __FILE__) . ' ' . $googleTv->getHumanName());
            $googleTv->overlayRelaunch($this->port());
        }
        $reply = $this->request($_path, $_body);
        $this->checkAndUpdateCmd('online', 1);
        return $reply;
    }

    public function refresh() {
        try {
            $this->ingest($this->request('/get')['result']);
        } catch (tvoverlaybeDown $e) {
            $this->checkAndUpdateCmd('online', 0);
            throw $e;
        }
    }

    /* La réponse de GET /get → commandes info. */
    public static function values($_result) {
        $values = array('online' => 1);
        $map = array(
            'screen' => array('status', 'isScreenOn', 'bool'),
            'notifications' => array('notifications', 'displayNotifications', 'bool'),
            'fixed' => array('notifications', 'displayFixedNotifications', 'bool'),
            'clock' => array('overlay', 'clockOverlayVisibility', 'int'),
            'background' => array('overlay', 'overlayVisibility', 'int'),
            'duration' => array('notifications', 'notificationDuration', 'int'),
            'corner' => array('overlay', 'hotCorner', 'string'),
            'permission' => array('status', 'permissionState', 'string'),
            'battery' => array('status', 'batteryOptimizationState', 'string'),
            'version' => array('status', 'version', 'string'),
        );
        foreach ($map as $logicalId => $path) {
            if (!isset($_result[$path[0]][$path[1]])) {
                continue;
            }
            $value = $_result[$path[0]][$path[1]];
            $values[$logicalId] = $path[2] === 'bool' ? ($value ? 1 : 0) : ($path[2] === 'int' ? (int) $value : (string) $value);
        }
        return $values;
    }

    public function ingest($_result) {
        if (!is_array($_result)) {
            return;
        }
        if (isset($_result['status']['id']) && $this->getLogicalId() === '') {
            $this->setLogicalId((string) $_result['status']['id']);
            $this->save(true);
        }
        foreach (self::values($_result) as $logicalId => $value) {
            $this->checkAndUpdateCmd($logicalId, $value);
        }
        $this->setCache('raw', $_result);
    }

    /* ============================================================= ORDRES */

    public static function jsonMessage($_options) {
        $text = trim((string) (isset($_options['message']) ? $_options['message'] : ''));
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new Exception(__('Le message doit être un objet JSON, par exemple', __FILE__) . ' {"title":"Sonnette","smallIcon":"mdi:bell"}');
        }
        return $data;
    }

    private static function slider($_options) {
        if (!isset($_options['slider']) || !is_numeric($_options['slider'])) {
            throw new Exception(__('Valeur numérique attendue.', __FILE__));
        }
        return (int) round((float) $_options['slider']);
    }

    /* Identifiant pris dans le message, ou à défaut le titre. */
    private static function idFrom($_options) {
        foreach (array('message', 'title') as $key) {
            if (isset($_options[$key]) && trim((string) $_options[$key]) !== '') {
                return trim((string) $_options[$key]);
            }
        }
        throw new Exception(__('Indiquez l\'identifiant (id) dans le message.', __FILE__));
    }

    /* Les réglages par défaut de l'équipement complètent une notification. */
    public function withDefaults($_body) {
        $defaults = array(
            'duration' => (int) $this->getConfiguration('default_duration', 0),
            'corner' => (string) $this->getConfiguration('default_corner', ''),
            'smallIcon' => (string) $this->getConfiguration('default_icon', ''),
        );
        foreach ($defaults as $key => $value) {
            if (!isset($_body[$key]) && (is_int($value) ? $value > 0 : $value !== '')) {
                $_body[$key] = $value;
            }
        }
        return $_body;
    }

    /* ======================================================== INDICATEURS */

    /* Instant d'expiration d'un indicateur, ou null s'il n'expire pas.
     * TvOverlay accepte une date epoch, une durée « 1y2w3d4h5m6s » ou des
     * secondes. */
    public static function expiresAt($_expiration, $_now = null) {
        $now = ($_now === null) ? time() : (int) $_now;
        if ($_expiration === null || $_expiration === '') {
            return null;
        }
        $text = strtolower(trim((string) $_expiration));
        if (preg_match('/^\d+$/', $text)) {
            $value = (int) $text;
            /* Au-delà d'un milliard, c'est une date (2001 et après). */
            return $value >= 1000000000 ? $value : $now + $value;
        }
        if (!preg_match('/^(?:(\d+)y)?(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $text, $m) || $text === '') {
            return null;
        }
        $units = array(1 => 31536000, 2 => 604800, 3 => 86400, 4 => 3600, 5 => 60, 6 => 1);
        $seconds = 0;
        foreach ($units as $index => $unit) {
            $seconds += isset($m[$index]) && $m[$index] !== '' ? (int) $m[$index] * $unit : 0;
        }
        return $now + $seconds;
    }

    /* id => instant d'expiration (ou null), sans les expirés. Gardé en base
     * (configuration du plugin) et non dans le cache de Jeedom, vidé au
     * redémarrage : un indicateur sans expiration doit rester retirable. */
    private function fixedKey() {
        return 'fixed::' . $this->getId();
    }

    public function fixedIds() {
        $ids = config::byKey($this->fixedKey(), __CLASS__, array());
        if (!is_array($ids)) {
            return array();
        }
        $now = time();
        return array_filter($ids, function ($at) use ($now) { return $at === null || $at > $now; });
    }

    public function rememberFixed($_fixed) {
        $ids = $this->fixedIds();
        $id = (string) $_fixed['id'];
        if (isset($_fixed['visible']) && $_fixed['visible'] === false) {
            unset($ids[$id]);
        } else {
            $ids[$id] = self::expiresAt(isset($_fixed['expiration']) ? $_fixed['expiration'] : null);
        }
        config::save($this->fixedKey(), $ids, __CLASS__);
        $this->publishFixed();
    }

    public function publishFixed() {
        $ids = array_keys($this->fixedIds());
        sort($ids);
        $this->checkAndUpdateCmd('fixed_list', implode(', ', $ids));
    }

    /* ====================================================== NOTIFICATIONS */

    /* Nombre de notifications dont le contenu est retenu pour le retrait. */
    const REMEMBERED = 30;

    public function unrememberNotification($_id) {
        $known = $this->getCache('notifications', array());
        if (is_array($known) && isset($known[$_id])) {
            unset($known[$_id]);
            $this->setCache('notifications', $known);
        }
    }

    public function rememberNotification($_notification) {
        if (!isset($_notification['id']) || trim((string) $_notification['id']) === '') {
            return;
        }
        $id = trim((string) $_notification['id']);
        $known = $this->getCache('notifications', array());
        if (!is_array($known)) {
            $known = array();
        }
        unset($known[$id]);
        /* Durée effective : celle de la notification, sinon celle de
         * l'appli, pour savoir si elle est encore à l'écran. */
        $duration = isset($_notification['duration']) ? (int) $_notification['duration'] : 0;
        if ($duration <= 0) {
            $info = $this->getCmd('info', 'duration');
            $duration = is_object($info) ? (int) $info->execCmd() : 0;
        }
        $known[$id] = array('body' => $_notification, 'until' => time() + ($duration > 0 ? $duration : 5));
        $this->setCache('notifications', array_slice($known, -self::REMEMBERED, null, true));
    }

    /* Ce qu'il faut renvoyer pour retirer une notification : son propre
     * contenu, d'une seconde, pour qu'elle s'efface sans changer d'aspect.
     * Sans la vidéo, qu'il serait inutile de rouvrir. Rend null si elle est
     * déjà terminée ou inconnue (envoyée sans id, par un autre système, ou
     * oubliée au redémarrage) : la renvoyer la ferait réapparaître. */
    public function dismissal($_id) {
        $known = $this->getCache('notifications', array());
        if (!is_array($known) || !isset($known[$_id]['body'], $known[$_id]['until']) || $known[$_id]['until'] < time()) {
            return null;
        }
        $body = $known[$_id]['body'];
        unset($body['video']);
        if (!isset($body['title']) && !isset($body['message'])) {
            $body['title'] = '…';
        }
        $body['id'] = $_id;
        $body['duration'] = 1;
        return $body;
    }

    public function runAction($_logicalId, $_options = array()) {
        if (!$this->isConfigured()) {
            throw new Exception(__('Adresse IP de la TV non renseignée.', __FILE__));
        }
        switch ($_logicalId) {
            case 'refresh':
                $this->refresh();
                return;
            case 'notify':
                $body = array();
                foreach (array('title', 'message') as $key) {
                    if (isset($_options[$key]) && trim((string) $_options[$key]) !== '') {
                        $body[$key] = (string) $_options[$key];
                    }
                }
                if (count($body) === 0) {
                    throw new Exception(__('Notification vide : renseignez un titre ou un message.', __FILE__));
                }
                $this->send('/notify', $this->withDefaults($body));
                return;
            case 'notify_json':
                $notification = $this->withDefaults(self::jsonMessage($_options));
                if (isset($notification['id'])) {
                    $notification['id'] = trim((string) $notification['id']);
                }
                $this->send('/notify', $notification);
                $this->rememberNotification($notification);
                return;
            case 'dismiss':
                $id = self::idFrom($_options);
                $body = $this->dismissal($id);
                if ($body !== null) {
                    $this->send('/notify', $body);
                } else {
                    log::add(__CLASS__, 'debug', $this->getHumanName() . ' : ' . __('notification déjà terminée ou inconnue, rien à retirer :', __FILE__) . ' ' . $id);
                }
                $this->unrememberNotification($id);
                return;
            case 'fixed_json':
                $fixed = self::jsonMessage($_options);
                if (!isset($fixed['id']) || trim((string) $fixed['id']) === '') {
                    throw new Exception(__('Un indicateur doit avoir un id, sans quoi il ne pourra plus être retiré. Exemple :', __FILE__)
                        . ' {"id":"lampe","icon":"mdi:lightbulb","message":"Salon"}');
                }
                $fixed['id'] = trim((string) $fixed['id']);
                if (array_key_exists('visible', $fixed)) {
                    $fixed['visible'] = filter_var($fixed['visible'], FILTER_VALIDATE_BOOLEAN);
                }
                if (isset($fixed['expiration']) && $fixed['expiration'] !== '' && self::expiresAt($fixed['expiration']) === null) {
                    throw new Exception(__('Expiration illisible :', __FILE__) . ' « ' . $fixed['expiration'] . ' ». '
                        . __('Formats acceptés : secondes (90), durée (30m, 12h, 1d2h) ou date epoch.', __FILE__));
                }
                $this->send('/notify_fixed', $fixed);
                $this->rememberFixed($fixed);
                return;
            case 'fixed_remove':
                $id = self::idFrom($_options);
                $this->send('/notify_fixed', array('id' => $id, 'visible' => false));
                $this->rememberFixed(array('id' => $id, 'visible' => false));
                return;
            case 'fixed_clear':
                /* Chaque id sort de la liste dès son retrait : un échec en
                 * cours de route laisse une liste juste. */
                foreach (array_keys($this->fixedIds()) as $id) {
                    $this->send('/notify_fixed', array('id' => (string) $id, 'visible' => false));
                    $this->rememberFixed(array('id' => (string) $id, 'visible' => false));
                }
                return;
            case 'notifications_on':
            case 'notifications_off':
                $this->send('/set/notifications', array('displayNotifications' => $_logicalId === 'notifications_on'));
                break;
            case 'fixed_on':
            case 'fixed_off':
                $this->send('/set/notifications', array('displayFixedNotifications' => $_logicalId === 'fixed_on'));
                break;
            case 'clock_set':
                $this->send('/set/overlay', array('clockOverlayVisibility' => max(0, min(95, self::slider($_options)))));
                break;
            case 'background_set':
                $this->send('/set/overlay', array('overlayVisibility' => max(0, min(95, self::slider($_options)))));
                break;
            case 'duration_set':
                $this->send('/set/notifications', array('notificationDuration' => max(1, min(300, self::slider($_options)))));
                break;
            case 'corner_set':
                $corner = isset($_options['select']) ? (string) $_options['select'] : '';
                if (!isset(self::CORNERS[$corner])) {
                    throw new Exception(__('Coin inconnu :', __FILE__) . ' ' . $corner);
                }
                $this->send('/set/overlay', array('hotCorner' => $corner));
                break;
            default:
                throw new Exception(__('Commande inconnue :', __FILE__) . ' ' . $_logicalId);
        }
        /* Un réglage changé : l'état affiché suit sans attendre le cron. */
        try {
            $this->refresh();
        } catch (Throwable $e) {
        }
    }

    /* ============================================================ WIDGET */

    /* Commandes affichées par le widget : leur valeur de départ et leur
     * identifiant, que le script du widget suit en direct. */
    const WIDGET_INFOS = array('online', 'screen', 'fixed_list', 'notifications', 'fixed', 'clock', 'background');
    const WIDGET_ACTIONS = array('refresh', 'notify', 'fixed_remove', 'fixed_clear', 'notifications_on', 'notifications_off',
                                 'fixed_on', 'fixed_off', 'clock_set', 'background_set');

    /* Un widget à lui plutôt que la pile des commandes : les commandes JSON
     * sont faites pour les scénarios, pas pour le dashboard. */
    public function toHtml($_version = 'dashboard') {
        $replace = $this->preToHtml($_version);
        if (!is_array($replace)) {
            return $replace;
        }
        $version = jeedom::versionAlias($_version);
        /* Taille fixée par le contenu : une taille retenue par le dashboard
         * pour l'ancien widget couperait le formulaire. */
        $replace['#width#'] = '300px';
        $replace['#height#'] = 'auto';
        $ids = array();
        $state = array();
        foreach (array_merge(self::WIDGET_INFOS, self::WIDGET_ACTIONS) as $logicalId) {
            $cmd = $this->getCmd(null, $logicalId);
            if (!is_object($cmd)) {
                continue;
            }
            $ids[$logicalId] = (string) $cmd->getId();
            if ($cmd->getType() === 'info') {
                $value = $cmd->execCmd();
                $state[$logicalId] = ($value === null) ? '' : (string) $value;
            }
        }
        $replace['#refresh_id#'] = isset($ids['refresh']) ? $ids['refresh'] : '';
        /* En attribut HTML, échappé : le script les relit sans rien évaluer. */
        $replace['#tvo_ids#'] = htmlspecialchars(json_encode($ids), ENT_QUOTES);
        $replace['#tvo_state#'] = htmlspecialchars(json_encode($state, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $template = getTemplate('core', $version, 'tvoverlaybe', __CLASS__);
        $html = translate::exec($template, 'plugins/tvoverlaybe/core/template/' . $version . '/tvoverlaybe.html');
        return $this->postToHtml($_version, template_replace($replace, $html));
    }

    /* ========================================================= DÉCOUVERTE */

    /* Ce qui répond à une adresse. */
    public static function probe($_ip, $_port = self::DEFAULT_PORT) {
        $ip = trim((string) $_ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception(__('Adresse IP invalide :', __FILE__) . ' ' . $_ip);
        }
        $probe = new tvoverlaybe();
        $probe->setConfiguration('ip', $ip);
        $probe->setConfiguration('port', (int) $_port);
        try {
            $result = $probe->request('/get')['result'];
        } catch (tvoverlaybeDown $e) {
            throw new Exception(__('TvOverlay ne répond pas à cette adresse (port', __FILE__) . ' ' . (int) $_port . ').');
        }
        return array(
            'ip' => $ip,
            'port' => (int) $_port,
            'id' => isset($result['status']['id']) ? (string) $result['status']['id'] : '',
            'name' => isset($result['settings']['deviceName']) ? (string) $result['settings']['deviceName'] : $ip,
            'version' => isset($result['status']['version']) ? (string) $result['status']['version'] : '',
        );
    }

    /* Les TV déjà connues du plugin Google TV, et celles où TvOverlay
     * répond. */
    public static function candidates() {
        $found = array();
        if (!self::googleTvAvailable()) {
            return $found;
        }
        foreach (eqLogic::byType('googletvbe') as $googleTv) {
            $ip = (string) $googleTv->getConfiguration('ip');
            if ($ip === '') {
                continue;
            }
            $entry = array('ip' => $ip, 'name' => $googleTv->getName(), 'googletv' => $googleTv->getHumanName(), 'overlay' => false);
            try {
                $probe = self::probe($ip);
                $entry['overlay'] = true;
                $entry['version'] = $probe['version'];
            } catch (Throwable $e) {
                $entry['error'] = $e->getMessage();
            }
            $existing = self::byAddress($ip, isset($probe['id']) ? $probe['id'] : '');
            $entry['known'] = is_object($existing) ? $existing->getHumanName() : '';
            $found[] = $entry;
            unset($probe);
        }
        return $found;
    }

    public static function byAddress($_ip, $_id = '') {
        if ($_id !== '') {
            $eqLogic = self::byLogicalId($_id, __CLASS__);
            if (is_object($eqLogic)) {
                return $eqLogic;
            }
        }
        foreach (self::byType(__CLASS__) as $eqLogic) {
            if ($eqLogic->getConfiguration('ip') === $_ip) {
                return $eqLogic;
            }
        }
        return null;
    }

    /* TV créée sans avoir pu joindre TvOverlay (TV éteinte, appli arrêtée) :
     * l'identifiant de l'installation sera relu au premier contact. */
    public static function unprobed($_ip, $_port) {
        $ip = trim((string) $_ip);
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            throw new Exception(__('Adresse IP invalide :', __FILE__) . ' ' . $_ip);
        }
        return array('ip' => $ip, 'port' => (int) $_port > 0 ? (int) $_port : self::DEFAULT_PORT, 'id' => '', 'name' => 'TV ' . $ip, 'version' => '');
    }

    public static function createFromProbe($_device, $_name = '') {
        $eqLogic = self::byAddress($_device['ip'], $_device['id']);
        if (!is_object($eqLogic)) {
            $eqLogic = new tvoverlaybe();
            $eqLogic->setEqType_name(__CLASS__);
            $base = trim((string) $_name) !== '' ? trim((string) $_name) : $_device['name'];
            $base .= ' (TvOverlay)';
            $names = array();
            foreach (self::byType(__CLASS__) as $other) {
                $names[$other->getName()] = true;
            }
            $name = $base;
            for ($i = 2; isset($names[$name]); $i++) {
                $name = $base . ' ' . $i;
            }
            $eqLogic->setName($name);
            $eqLogic->setIsEnable(1);
            $eqLogic->setIsVisible(1);
            $eqLogic->setCategory('multimedia', 1);
        }
        if ($_device['id'] !== '') {
            $eqLogic->setLogicalId($_device['id']);
        }
        $eqLogic->setConfiguration('ip', $_device['ip']);
        $eqLogic->setConfiguration('port', $_device['port']);
        $eqLogic->save();
        try {
            $eqLogic->refresh();
        } catch (Throwable $e) {
        }
        return $eqLogic;
    }

    public function toAjax() {
        $googleTv = null;
        try {
            $googleTv = $this->googleTv();
        } catch (Throwable $e) {
        }
        return array(
            'id' => $this->getId(),
            'googletv' => is_object($googleTv) ? $googleTv->getHumanName() : '',
            'googletv_plugin' => self::googleTvAvailable(),
            'relaunch' => (int) $this->getConfiguration('relaunch', 1),
            'raw' => $this->getCache('raw', null),
        );
    }
}

class tvoverlaybeCmd extends cmd {

    public function execute($_options = array()) {
        if ($this->getType() !== 'action') {
            return;
        }
        $eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic)) {
            return;
        }
        $eqLogic->runAction($this->getLogicalId(), $_options);
    }
}

/* TvOverlay ne répond pas du tout : arrêtée, ou TV éteinte. */
class tvoverlaybeDown extends Exception {
}
