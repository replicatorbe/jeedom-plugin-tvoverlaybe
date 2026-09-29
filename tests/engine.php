<?php
/* Le moteur des indicateurs automatiques de tvoverlaybe.class.php, contre
 * des doublures du coeur. Inclus par tests/run.php (check(), $now).
 *
 * Aucune classe du vrai coeur n'est chargée : Jeedom n'est ni lu ni écrit,
 * aucune requête ne part (request() est remplacée), et le seul fichier créé
 * est le verrou, dans un dossier temporaire supprimé à la fin. */

/* ----------------------------------------------------------- DOUBLURES */

function __($_text, $_file = null) {
    return $_text;
}

class log {
    public static $lines = array();
    public static function add($_plugin, $_level, $_message) {
        self::$lines[] = $_level . ' ' . $_message;
    }
}

class config {
    public static $data = array();
    public static function byKey($_key, $_plugin = 'core', $_default = '') {
        return isset(self::$data[$_plugin][$_key]) ? self::$data[$_plugin][$_key] : $_default;
    }
    public static function save($_key, $_value, $_plugin = 'core') {
        self::$data[$_plugin][$_key] = $_value;
    }
    public static function remove($_key, $_plugin = 'core') {
        unset(self::$data[$_plugin][$_key]);
    }
}

class jeedom {
    public static $tmp = '';
    public static function getTmpFolder($_plugin = '') {
        return self::$tmp;
    }
}

/* plugin::byId() lève une exception pour un plugin absent : pas de Google
 * TV ici. */
class plugin {
    public static function byId($_id) {
        throw new Exception('plugin absent');
    }
}

class listener {
    public static $all = array();
    private $_class = '';
    private $_function = '';
    private $_option = array();
    private $_event = array();
    public static function byClassAndFunction($_class, $_function, $_option = '') {
        $key = $_class . '::' . $_function . '::' . json_encode($_option);
        return isset(self::$all[$key]) ? self::$all[$key] : null;
    }
    public function setClass($_v) { $this->_class = $_v; }
    public function setFunction($_v) { $this->_function = $_v; }
    public function setOption($_v) { $this->_option = $_v; }
    public function emptyEvent() { $this->_event = array(); }
    public function addEvent($_id) { $this->_event[] = '#' . trim($_id, '#') . '#'; }
    public function getEvent() { return $this->_event; }
    public function save() { self::$all[$this->_class . '::' . $this->_function . '::' . json_encode($this->_option)] = $this; }
    public function remove() { unset(self::$all[$this->_class . '::' . $this->_function . '::' . json_encode($this->_option)]); }
}

class cmd {
    public static $registry = array();
    public $_type = 'info';
    public $_value = null;
    public $_logicalId = '';
    public static function byId($_id) {
        return isset(self::$registry[(int) $_id]) ? self::$registry[(int) $_id] : null;
    }
    public function getType() { return $this->_type; }
    public function execCmd() { return $this->_value; }
    public function getLogicalId() { return $this->_logicalId; }
}

class eqLogic {
    public $_id = 7;
    public $_enable = 1;
    public $_config = array();
    public $_cache = array();
    public $_cmds = array();
    public function getId() { return $this->_id; }
    public function getIsEnable() { return $this->_enable; }
    public function getEqType_name() { return 'tvoverlaybe'; }
    public function getHumanName() { return '[TV]'; }
    public function getLogicalId() { return 'tvo'; }
    public function getConfiguration($_key = '', $_default = '') {
        return isset($this->_config[$_key]) ? $this->_config[$_key] : $_default;
    }
    public function setConfiguration($_key, $_value) { $this->_config[$_key] = $_value; }
    public function setIsEnable($_v) { $this->_enable = $_v; }
    public function setIsVisible($_v) { }
    public function getCache($_key = '', $_default = '') {
        return array_key_exists($_key, $this->_cache) ? $this->_cache[$_key] : $_default;
    }
    public function setCache($_key, $_value = null) { $this->_cache[$_key] = $_value; }
    public function getCmd($_type = null, $_logicalId = null) {
        if (!isset($this->_cmds[$_logicalId])) {
            $cmd = new cmd();
            $cmd->_logicalId = $_logicalId;
            $this->_cmds[$_logicalId] = $cmd;
        }
        return $this->_cmds[$_logicalId];
    }
    public function checkAndUpdateCmd($_logicalId, $_value) {
        $this->getCmd('info', $_logicalId)->_value = $_value;
        return true;
    }
}

/* La classe du plugin, sans le coeur : le require de core.inc.php retiré,
 * celui de tvoverlaybeAuto déjà satisfait par run.php. */
$code = file_get_contents(__DIR__ . '/../core/class/tvoverlaybe.class.php');
$code = preg_replace('#^\s*require_once .*$#m', '', $code);
eval('?>' . $code);

/* request() remplacée : on note ce qui serait parti. */
class tvoverlaybeFake extends tvoverlaybe {
    public $_sent = array();
    public $_down = false;
    public $_refuse = false;
    public function request($_path, $_body = null, $_timeout = self::TIMEOUT) {
        if ($this->_down) {
            throw new tvoverlaybeDown('TvOverlay ne répond pas');
        }
        if ($this->_refuse) {
            throw new Exception('TvOverlay refuse : icône');
        }
        $this->_sent[] = array($_path, $_body);
        return array('success' => true, 'result' => array());
    }
    public function takeSent() {
        $sent = $this->_sent;
        $this->_sent = array();
        return $sent;
    }
}

jeedom::$tmp = sys_get_temp_dir() . '/tvoverlaybe-tests-' . getmypid();
@mkdir(jeedom::$tmp);

function tvoCmd($_id, $_value) {
    $cmd = new cmd();
    $cmd->_value = $_value;
    cmd::$registry[$_id] = $cmd;
    return $cmd;
}

/* ------------------------------------------------------------- ESSAIS */

$tv = new tvoverlaybeFake();
$tv->setConfiguration('ip', '192.168.0.106');
$tv->checkAndUpdateCmd('online', 1);
tvoCmd(101, '21.6');
tvoCmd(102, 'mdi:weather-sunny');
tvoCmd(201, '0');
tvoCmd(202, '0');
tvoCmd(203, '0');
$tv->setConfiguration('auto_fixed', array($meteo, $lampe));

/* Enregistrement : contrôle, forme complète, écouteur. */
$tv->preSave();
check('preSave : liste normalisée', $tv->getConfiguration('auto_fixed')[1]['combine'], 'any');
$tv->updateAutoListener();
$l = listener::byClassAndFunction('tvoverlaybe', 'pull', array('eqLogic_id' => 7));
check('écouteur : commandes citées', is_object($l) ? $l->getEvent() : null, array('#101#', '#102#', '#201#', '#202#', '#203#'));

$bad = new tvoverlaybeFake();
$bad->setConfiguration('auto_fixed', array(array('name' => 'sans id')));
try {
    $bad->preSave();
    check('preSave : id manquant refusé', 'accepté', 'refusé');
} catch (Exception $e) {
    check('preSave : id manquant refusé', strpos($e->getMessage(), 'id obligatoire') !== false, true);
}
$new = new tvoverlaybeFake();
$new->_id = '';
$new->setConfiguration('auto_fixed', array(array('name' => 'sans id')));
$new->preSave();
check('preSave : jamais d\'exception à la création', true, true);

/* Premier calcul : météo envoyée, ampoule (cachée, état inconnu) retirée par
 * prudence. */
$tv->autoSync('save');
$sent = $tv->takeSent();
check('1er calcul : deux requêtes', count($sent), 2);
check('1er calcul : météo', $sent[0], array('/notify_fixed', array('id' => 'meteo', 'message' => '22°', 'icon' => 'mdi:weather-sunny', 'shape' => 'circle', 'expiration' => '12h')));
check('1er calcul : retrait ampoule', $sent[1], array('/notify_fixed', array('id' => 'lampe', 'visible' => false)));
check('liste des indicateurs affichés', $tv->infoValue('fixed_list'), 'meteo');

/* Rien n'a changé : rien ne part. */
$tv->autoSync('event');
check('sans changement : aucune requête', $tv->takeSent(), array());

/* 21.6 → 21.8 : même texte arrondi, rien ne part. */
cmd::$registry[101]->_value = '21.8';
$tv->autoSync('event');
check('même arrondi : aucune requête', $tv->takeSent(), array());

/* Une lampe s'allume. */
cmd::$registry[202]->_value = '1';
$tv->autoSync('event');
$sent = $tv->takeSent();
check('lampe allumée : ampoule envoyée', array(count($sent), $sent[0][1]['id'], $sent[0][1]['iconColor']), array(1, 'lampe', '#ff9800'));
check('liste : les deux', $tv->infoValue('fixed_list'), 'lampe, meteo');

/* Une deuxième lampe : toujours visible, même contenu, rien ne part. */
cmd::$registry[201]->_value = '1';
$tv->autoSync('event');
check('deuxième lampe : aucune requête', $tv->takeSent(), array());

/* Renouvellement : envoyée il y a 11 h. */
$states = $tv->getCache('auto_state');
$states['meteo']['sent_at'] = time() - 11 * 3600;
$tv->setCache('auto_state', $states);
$tv->autoSync('cron');
$sent = $tv->takeSent();
check('renouvellement avant expiration', array(count($sent), $sent[0][1]['id']), array(1, 'meteo'));

/* « Retirer tous les indicateurs » : les deux retirés, et pas remis. */
$tv->runAction('fixed_clear');
$sent = $tv->takeSent();
check('tout retirer : deux retraits', count($sent), 2);
check('tout retirer : liste vide', $tv->infoValue('fixed_list'), '');
$tv->autoSync('cron');
check('tout retirer : pas remis au calcul suivant', $tv->takeSent(), array());
$tv->autoLost('essai');
$tv->autoSync('cron');
check('tout retirer : pas remis après relance de TvOverlay', $tv->takeSent(), array());

/* La température change : la météo revient. Les lampes, inchangées,
 * restent retirées. */
cmd::$registry[101]->_value = '23.2';
$tv->autoSync('event');
$sent = $tv->takeSent();
check('retirée puis changée : météo revient seule', array(count($sent), $sent[0][1]['id'], $sent[0][1]['message']), array(1, 'meteo', '23°'));

/* Toutes les lampes éteintes, puis une rallumée : l'ampoule revient. */
cmd::$registry[201]->_value = '0';
cmd::$registry[202]->_value = '0';
$tv->autoSync('event');
check('ampoule retirée à la main puis éteinte : pas de retrait inutile', $tv->takeSent(), array());
cmd::$registry[203]->_value = '1';
$tv->autoSync('event');
$sent = $tv->takeSent();
check('lampe rallumée : ampoule revient', array(count($sent), $sent[0][1]['id']), array(1, 'lampe'));

/* « Retirer un indicateur » sur l'ampoule : retirée et pas remise. */
$tv->runAction('fixed_remove', array('message' => 'lampe'));
$tv->takeSent();
$tv->autoSync('event');
check('retirer un indicateur : pas remis', $tv->takeSent(), array());

/* Invisible → retrait propre. */
cmd::$registry[203]->_value = '0';
$tv->autoSync('event');
cmd::$registry[203]->_value = '1';
$tv->autoSync('event');
$tv->takeSent();
cmd::$registry[203]->_value = '0';
$tv->autoSync('event');
$sent = $tv->takeSent();
check('lampes éteintes : retrait', $sent, array(array('/notify_fixed', array('id' => 'lampe', 'visible' => false))));

/* TvOverlay ne répond pas : rien noté, « En ligne » à 0, puis plus aucun
 * essai tant qu'elle n'est pas revenue. */
cmd::$registry[101]->_value = '24';
$tv->_down = true;
$tv->autoSync('event');
check('TvOverlay arrêtée : « En ligne » à 0', $tv->infoValue('online'), '0');
$tv->_down = false;
$tv->autoSync('event');
check('En ligne à 0 : pas d\'essai', $tv->takeSent(), array());
/* Le cron la retrouve (voir cron()) : republication. */
$tv->checkAndUpdateCmd('online', 1);
$tv->autoLost('TvOverlay répond de nouveau');
$tv->autoSync('cron');
$sent = $tv->takeSent();
check('retour de TvOverlay : météo en attente envoyée', array(count($sent), $sent[0][1]['message']), array(1, '24°'));

/* Refus de TvOverlay : noté au journal, pas réessayé chaque minute. */
cmd::$registry[102]->_value = 'n importe quoi';
$tv->_refuse = true;
log::$lines = array();
$tv->autoSync('event');
$tv->_refuse = false;
check('refus : journalisé', count(array_filter(log::$lines, function ($_l) { return strpos($_l, 'error') === 0; })), 1);
$tv->autoSync('cron');
check('refus : pas réessayé', $tv->takeSent(), array());

/* Indicateur supprimé de l'équipement : retiré de l'écran, oublié. */
cmd::$registry[102]->_value = 'weather-rainy';
$tv->autoSync('event');
check('icône sans préfixe complétée', $tv->takeSent()[0][1]['icon'], 'mdi:weather-rainy');
$tv->setConfiguration('auto_fixed', array($lampe));
$tv->autoSync('save');
check('supprimé : retiré', $tv->takeSent(), array(array('/notify_fixed', array('id' => 'meteo', 'visible' => false))));
check('supprimé : oublié', array_keys($tv->getCache('auto_state')), array('lampe'));
$tv->updateAutoListener();
$l = listener::byClassAndFunction('tvoverlaybe', 'pull', array('eqLogic_id' => 7));
check('écouteur : suit la liste', $l->getEvent(), array('#201#', '#202#', '#203#'));

/* Désactivé : retiré aussi. Plus rien à écouter : plus d'écouteur. */
$tv->setConfiguration('auto_fixed', array(array_merge($meteo, array('enable' => 0)), array_merge($lampe, array('enable' => 0))));
$tv->updateAutoListener();
check('tous désactivés : écouteur retiré', listener::byClassAndFunction('tvoverlaybe', 'pull', array('eqLogic_id' => 7)), null);

/* Démarrage de Jeedom : l'affiché redevient inconnu, et se republie. */
$tv->setConfiguration('auto_fixed', array($meteo));
$tv->autoSync('save');
$tv->takeSent();
$tv->autoLost('démarrage de Jeedom');
check('démarrage : état inconnu', $tv->getCache('auto_state')['meteo']['shown'], null);
$tv->autoSync('cron');
check('démarrage : republié', count($tv->takeSent()), 1);

/* Un indicateur de scénario (Indicateur (JSON)) n'est pas touché par le
 * moteur, et « Retirer un indicateur » sur lui ne met rien en retrait. */
$tv->runAction('fixed_json', array('message' => '{"id":"alarme","icon":"mdi:alarm-light"}'));
$tv->autoSync('cron');
$tv->takeSent();
check('indicateur de scénario : liste', $tv->infoValue('fixed_list'), 'alarme, meteo');
$tv->runAction('fixed_remove', array('message' => 'alarme'));
check('indicateur de scénario : aucun état automatique', array_keys($tv->getCache('auto_state')), array('meteo'));

/* TV sans adresse, ou désactivée : aucun calcul. */
$off = new tvoverlaybeFake();
$off->setConfiguration('auto_fixed', array($meteo));
$off->autoSync('cron');
check('sans adresse IP : rien', $off->takeSent(), array());

@unlink(jeedom::$tmp . '/auto-7.lock');
@rmdir(jeedom::$tmp);
