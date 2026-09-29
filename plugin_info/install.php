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

require_once __DIR__ . '/../../../core/php/core.inc.php';

function tvoverlaybe_install() {
}

/* Exécutée dans la requête HTTP de la page des plugins : rien de lent ici,
 * aucune interrogation de TV. On rattrape les commandes ajoutées, et
 * l'écouteur des indicateurs automatiques (0.2.0) est reconstruit d'après la
 * configuration existante, sans l'enregistrer ni la modifier : sans
 * indicateur automatique, il n'y a rien à écouter et rien n'est créé. */
function tvoverlaybe_update() {
    /* Une TV en erreur n'empêche pas la mise à jour des autres. */
    foreach (eqLogic::byType('tvoverlaybe') as $eqLogic) {
        try {
            $eqLogic->createCommands();
            $eqLogic->updateAutoListener();
        } catch (Throwable $e) {
            log::add('tvoverlaybe', 'error', $eqLogic->getHumanName() . ' : ' . $e->getMessage());
        }
    }
}

function tvoverlaybe_remove() {
}
