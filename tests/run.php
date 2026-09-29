<?php
/* Jeu d'essai hors ligne : calculs qui ne parlent ni à Jeedom ni à la TV.
 *
 *   php tests/run.php
 *
 * Trois parties : l'expiration des indicateurs (méthode extraite de la
 * classe), la logique des indicateurs automatiques (tvoverlaybeAuto, sans
 * dépendance), puis le moteur de la classe elle-même contre des doublures du
 * coeur (tests/engine.php) — aucun Jeedom chargé, aucune requête réseau,
 * rien d'écrit hors d'un dossier temporaire propre à l'essai.
 */
error_reporting(E_ALL);
/* La classe charge le coeur : on n'en extrait que la méthode statique à
 * tester, sans l'inclure. */
$source = file_get_contents(__DIR__ . '/../core/class/tvoverlaybe.class.php');
preg_match('/    public static function expiresAt\(.*?\n    }\n/s', $source, $m);
eval('class T { ' . $m[0] . ' }');

$failures = 0;
$count = 0;
function check($_label, $_actual, $_expected) {
    global $failures, $count;
    $count++;
    if ($_actual !== $_expected) {
        $failures++;
        echo "ÉCHEC $_label\n  attendu : " . var_export($_expected, true) . "\n  obtenu  : " . var_export($_actual, true) . "\n";
    }
}
$now = 1800000000;
check('sans expiration', T::expiresAt(null, $now), null);
check('vide', T::expiresAt('', $now), null);
check('secondes (entier)', T::expiresAt(90, $now), $now + 90);
check('secondes (texte)', T::expiresAt('90', $now), $now + 90);
check('date epoch', T::expiresAt(1695693410, $now), 1695693410);
check('12h', T::expiresAt('12h', $now), $now + 43200);
check('30m', T::expiresAt('30m', $now), $now + 1800);
check('1y2w3d4h5m6s', T::expiresAt('1y2w3d4h5m6s', $now), $now + 31536000 + 1209600 + 259200 + 14400 + 300 + 6);
check('majuscules', T::expiresAt('2H', $now), $now + 7200);
check('illisible', T::expiresAt('demain', $now), null);

/* ================================================ INDICATEURS AUTOMATIQUES */

require_once __DIR__ . '/../core/class/tvoverlaybeAuto.class.php';
$A = 'tvoverlaybeAuto';

/* Valeurs de commandes simulées : id → valeur. */
$values = array();
$valueOf = function ($_id) use (&$values) { return array_key_exists($_id, $values) ? $values[$_id] : null; };

/* --- Les deux exemples de la documentation. */
$meteo = array('id' => 'meteo', 'name' => 'Météo', 'visibility' => 'always',
               'text_mode' => 'cmd', 'text_cmd' => '#101#', 'decimals' => '0', 'suffix' => '°',
               'icon_mode' => 'cmd', 'icon_cmd' => '#102#', 'icon' => 'mdi:weather-cloudy',
               'shape' => 'circle', 'expiration' => '12h');
$lampe = array('id' => 'lampe', 'name' => 'Lampes', 'visibility' => 'conditions', 'combine' => 'any',
               'conditions' => array(array('cmd' => '#201#', 'operator' => '==', 'value' => '1'),
                                     array('cmd' => '#202#', 'operator' => '==', 'value' => '1'),
                                     array('cmd' => '#203#', 'operator' => '==', 'value' => '1')),
               'text_mode' => 'none', 'icon_mode' => 'fixed', 'icon' => 'mdi:lightbulb',
               'iconColor' => '#ff9800', 'borderColor' => '#ff9800', 'shape' => 'circle');

/* --- Lecture. */
check('cmdId #12#', $A::cmdId('#12#'), 12);
check('cmdId 12', $A::cmdId(' 12 '), 12);
check('cmdId nom lisible', $A::cmdId('#[Salon][Lampe][Etat]#'), 0);
check('seconds 12h', $A::seconds('12h'), 43200);
check('seconds vide', $A::seconds(''), null);
check('seconds epoch refusée', $A::seconds('1695693410'), null);
check('seconds 0 refusée', $A::seconds('0'), null);
$n = $A::normalize(array('id' => ' x '));
check('normalize : défauts', array($n['enable'], $n['id'], $n['visibility'], $n['combine'], $n['text_mode'], $n['icon_mode'], $n['expiration']),
      array(1, 'x', 'always', 'any', 'none', 'fixed', '12h'));
check('normalize : désactivé (false)', $A::normalize(array('enable' => false))['enable'], 0);
check('normalize : désactivé ("0")', $A::normalize(array('enable' => '0'))['enable'], 0);
check('normalize : opérateur inconnu → ==', $A::normalize(array('conditions' => array(array('cmd' => '#1#', 'operator' => '=~'))))['conditions'][0]['operator'], '==');
check('normalize : expiration illisible → 12h', $A::normalize(array('expiration' => 'demain'))['expiration'], '12h');
check('normalize : forme inconnue → appli', $A::normalize(array('shape' => 'star'))['shape'], '');
check('normalizeAll : JSON texte', count($A::normalizeAll(json_encode(array($meteo, $lampe)))), 2);

/* --- Contrôles de saisie. */
check('errors : exemples valides', $A::errors(array($meteo, $lampe)), array());
check('errors : id manquant', count($A::errors(array(array('name' => 'sans id')))), 1);
check('errors : id en double', count($A::errors(array($meteo, $meteo))), 1);
check('errors : Visible si sans condition', count($A::errors(array(array('id' => 'a', 'visibility' => 'conditions')))), 1);
check('errors : texte commande sans commande', count($A::errors(array(array('id' => 'a', 'text_mode' => 'cmd')))), 1);
check('cmdIds météo', $A::cmdIds($meteo), array(101, 102));
check('cmdIds lampes', $A::cmdIds($lampe), array(201, 202, 203));
check('cmdIds : commande de texte inutilisée ignorée', $A::cmdIds(array('text_mode' => 'fixed', 'text_cmd' => '#5#')), array());

/* --- Comparaisons. */
check('== nombres', $A::compare('1', '==', '1'), true);
check('== 1.0 et 1', $A::compare('1.0', '==', '1'), true);
check('== texte sans casse', $A::compare('ON', '==', 'on'), true);
check('!= texte', $A::compare('off', '!=', 'on'), true);
check('> nombres', $A::compare('21.5', '>', '20'), true);
check('9 < 10 en nombres', $A::compare('9', '<', '10'), true);
check('>= égal', $A::compare('20', '>=', '20'), true);
check('<= faux', $A::compare('21', '<=', '20'), false);
check('> sur du texte : faux', $A::compare('abc', '>', '3'), false);
check('commande absente : faux', $A::compare(null, '!=', '1'), false);
check('valeur vide : faux', $A::compare('', '!=', '1'), false);

/* --- Visibilité OU / ET. */
$values = array(201 => '0', 202 => '0', 203 => '0');
check('OU : aucune lampe', $A::isVisible($lampe, $valueOf), false);
$values[202] = '1';
check('OU : une lampe', $A::isVisible($lampe, $valueOf), true);
$tous = array_merge($lampe, array('combine' => 'all'));
check('ET : une seule lampe', $A::isVisible($tous, $valueOf), false);
$values = array(201 => '1', 202 => '1', 203 => '1');
check('ET : toutes', $A::isVisible($tous, $valueOf), true);
unset($values[203]);
check('ET : une commande disparue', $A::isVisible($tous, $valueOf), false);
check('toujours', $A::isVisible($meteo, $valueOf), true);
check('Visible si sans condition : jamais', $A::isVisible(array('visibility' => 'conditions'), $valueOf), false);

/* --- Mise en forme du texte. */
check('arrondi 0 décimale', $A::formatText('21.6', '0', '°'), '22°');
check('arrondi 1 décimale, virgule', $A::formatText('21.64', '1', '°'), '21,6°');
check('arrondi, point', $A::formatText('21.64', '1', '', '.'), '21.6');
check('-0.4 → 0', $A::formatText('-0.4', '0', '°'), '0°');
check('-2.6 → -3', $A::formatText('-2.6', '0', '°'), '-3°');
check('sans arrondi : tel quel', $A::formatText('21.64', '', ' °C'), '21.64 °C');
check('texte non numérique', $A::formatText('Pluie', '0', '!'), 'Pluie!');
check('vide : pas de suffixe seul', $A::formatText('', '0', '°'), '');
check('null', $A::formatText(null, '0', '°'), '');
check('icône sans préfixe', $A::iconName('weather-rainy'), 'mdi:weather-rainy');
check('icône mdi:', $A::iconName('mdi:weather-rainy'), 'mdi:weather-rainy');
check('icône adresse', $A::iconName('http://x/y.png'), 'http://x/y.png');

/* --- Ce qui part vers TvOverlay. */
$values = array(101 => '21.6', 102 => 'mdi:weather-partly-cloudy');
check('corps météo', $A::body($meteo, $valueOf),
      array('id' => 'meteo', 'message' => '22°', 'icon' => 'mdi:weather-partly-cloudy', 'shape' => 'circle'));
$values = array(101 => '21.6');
check('météo : icône de repli', $A::body($meteo, $valueOf)['icon'], 'mdi:weather-cloudy');
$values = array(201 => '1');
check('corps ampoule', $A::body($lampe, $valueOf),
      array('id' => 'lampe', 'icon' => 'mdi:lightbulb', 'iconColor' => '#ff9800', 'borderColor' => '#ff9800', 'shape' => 'circle'));
$values = array(201 => '0');
check('ampoule cachée', $A::body($lampe, $valueOf), null);
check('texte fixe', $A::body(array('id' => 'a', 'text_mode' => 'fixed', 'text' => 'Salon'), $valueOf)['message'], 'Salon');
check('empreinte stable (ordre des clés)', $A::signature(array('id' => 'a', 'icon' => 'b')), $A::signature(array('icon' => 'b', 'id' => 'a')));

/* --- Renouvellement avant expiration. */
check('12h : pas avant 11 h', $A::refreshDue($now, '12h', $now + 11 * 3600 - 1), false);
check('12h : à 11 h', $A::refreshDue($now, '12h', $now + 11 * 3600), true);
check('10m : à mi-chemin', $A::refreshDue($now, '10m', $now + 300), true);
check('10m : pas avant', $A::refreshDue($now, '10m', $now + 299), false);
check('30s relevées à 2 min', $A::refreshDue($now, '30s', $now + 59), false);

/* --- Décisions : n'envoyer que ce qui change. */
$b1 = array('id' => 'meteo', 'message' => '22°', 'icon' => 'mdi:weather-sunny');
$b2 = array('id' => 'meteo', 'message' => '23°', 'icon' => 'mdi:weather-sunny');
$d = $A::decide($b1, null, '12h', $now);
check('premier calcul : envoi', $d['action'], 'send');
$s = $d['state'];
check('état après envoi', array($s['shown'], $s['sent_at']), array(true, $now));
check('même contenu : rien', $A::decide($b1, $s, '12h', $now + 600)['action'], 'none');
check('texte changé : envoi', $A::decide($b2, $s, '12h', $now + 600)['action'], 'send');
check('même contenu, 11 h plus tard : renouvelé', $A::decide($b1, $s, '12h', $now + 11 * 3600)['action'], 'send');
$d = $A::decide(null, $s, '12h', $now + 600);
check('devenu invisible : retrait', $d['action'], 'remove');
check('retiré : rien ensuite', $A::decide(null, $d['state'], '12h', $now + 700)['action'], 'none');
check('état inconnu et invisible : retrait par prudence', $A::decide(null, null, '12h', $now)['action'], 'remove');
check('réapparition : envoi', $A::decide($b1, $d['state'], '12h', $now + 800)['action'], 'send');

/* --- Écran perdu (Jeedom redémarré, TvOverlay relancée, TV rallumée). */
$lost = $A::lost(array('meteo' => $s, 'lampe' => array('shown' => false, 'sig' => '', 'sent_at' => 0, 'snoozed' => null)));
check('perdu : affiché → inconnu', $lost['meteo']['shown'], null);
check('perdu : retiré reste retiré', $lost['lampe']['shown'], false);
check('perdu : republié', $A::decide($b1, $lost['meteo'], '12h', $now + 60)['action'], 'send');

/* --- Retrait à la main. */
$z = $A::snooze($s);
check('retiré à la main : pas remis', $A::decide($b1, $z, '12h', $now + 60)['action'], 'none');
check('retiré à la main : pas remis au renouvellement', $A::decide($b1, $z, '12h', $now + 20 * 3600)['action'], 'none');
check('retiré à la main : pas remis après relance', $A::decide($b1, $A::lost(array('m' => $z))['m'], '12h', $now + 60)['action'], 'none');
check('retiré à la main : revient si le contenu change', $A::decide($b2, $z, '12h', $now + 60)['action'], 'send');
$h = $A::decide(null, $z, '12h', $now + 60);
check('retiré puis caché : pas de retrait inutile', $h['action'], 'none');
check('retiré puis caché puis visible : revient', $A::decide($b1, $h['state'], '12h', $now + 120)['action'], 'send');

/* ============================================ LE MOTEUR, CONTRE DOUBLURES */

require __DIR__ . '/engine.php';

echo $count . ' contrôles, ' . $failures . " échec(s)\n";
exit($failures > 0 ? 1 : 0);
