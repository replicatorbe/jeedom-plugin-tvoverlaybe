<?php
/* Jeu d'essai hors ligne : calculs qui ne parlent ni à Jeedom ni à la TV.
 *
 *   php tests/run.php
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
echo $count . ' contrôles, ' . $failures . " échec(s)\n";
exit($failures > 0 ? 1 : 0);
