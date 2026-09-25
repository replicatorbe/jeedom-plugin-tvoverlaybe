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
try {
    require_once __DIR__ . '/../../../../core/php/core.inc.php';
    include_file('core', 'authentification', 'php');

    if (!isConnect('admin')) {
        throw new Exception(__('401 - Accès non autorisé', __FILE__));
    }
    ajax::init();

    function tvoverlaybeEq() {
        $eqLogic = eqLogic::byId(init('id'));
        if (!is_object($eqLogic) || $eqLogic->getEqType_name() != 'tvoverlaybe') {
            throw new Exception(__('Équipement introuvable :', __FILE__) . ' ' . init('id'));
        }
        return $eqLogic;
    }

    /* Les TV du plugin Google TV, et si TvOverlay y répond. */
    if (init('action') == 'candidates') {
        unautorizedInDemo();
        ajax::success(tvoverlaybe::candidates());
    }

    if (init('action') == 'probe') {
        unautorizedInDemo();
        $device = tvoverlaybe::probe(init('ip'), init('port', tvoverlaybe::DEFAULT_PORT));
        $existing = tvoverlaybe::byAddress($device['ip'], $device['id']);
        $device['known'] = is_object($existing) ? $existing->getHumanName() : '';
        ajax::success($device);
    }

    /* Crée les TV retenues ; chacune est réinterrogée. */
    if (init('action') == 'create') {
        unautorizedInDemo();
        $entries = json_decode(init('entries'), true);
        if (!is_array($entries) || count($entries) === 0) {
            throw new Exception(__('Aucune TV à créer.', __FILE__));
        }
        $created = array();
        $errors = array();
        foreach ($entries as $entry) {
            try {
                $port = isset($entry['port']) ? $entry['port'] : tvoverlaybe::DEFAULT_PORT;
                $device = empty($entry['force']) ? tvoverlaybe::probe($entry['ip'], $port) : tvoverlaybe::unprobed($entry['ip'], $port);
                $created[] = tvoverlaybe::createFromProbe($device, isset($entry['name']) ? $entry['name'] : '')->getId();
            } catch (Throwable $e) {
                $errors[] = $entry['ip'] . ' : ' . $e->getMessage();
            }
        }
        ajax::success(array('created' => $created, 'errors' => $errors));
    }

    if (init('action') == 'test') {
        unautorizedInDemo();
        $eqLogic = tvoverlaybeEq();
        $eqLogic->send('/notify', $eqLogic->withDefaults(array(
            'id' => 'jeedom_test', 'title' => 'Jeedom', 'message' => __('Notification de test', __FILE__),
            'smallIcon' => 'mdi:home-automation',
        )));
        ajax::success();
    }

    if (init('action') == 'refresh') {
        unautorizedInDemo();
        $eqLogic = tvoverlaybeEq();
        $eqLogic->refresh();
        ajax::success($eqLogic->toAjax());
    }

    if (init('action') == 'data') {
        ajax::success(tvoverlaybeEq()->toAjax());
    }

    throw new Exception(__('Aucune méthode correspondante à :', __FILE__) . ' ' . init('action'));

/* Throwable : en PHP 8 une Error n'hérite pas d'Exception. */
} catch (Throwable $e) {
    ajax::error(displayException($e), $e->getCode());
}
