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

/* ================================================================== OUTILS */

function tvoverlaybeEl(_id) {
  return document.getElementById(_id)
}

/* Ce qui vient d'une TV ou du serveur est du texte, jamais du balisage. */
function tvoverlaybeEscape(_text) {
  var div = document.createElement('div')
  div.textContent = (_text === null || _text === undefined) ? '' : String(_text)
  return div.innerHTML
}

/* Les fenêtres du coeur : jeeDialog depuis Jeedom 4.4, bootbox sinon (il
   n'est chargé qu'avec jQuery). */
function tvoverlaybeConfirm(_title, _message, _callback) {
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.confirm({ title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } })
  } else {
    bootbox.confirm({ title: _title, message: _message, callback: function (_ok) { if (_ok) { _callback() } } })
  }
}

function tvoverlaybePrompt(_title, _value, _placeholder, _callback) {
  var options = { title: _title, value: _value, placeholder: _placeholder, callback: function (_v) { if (_v !== null) { _callback(_v) } } }
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.prompt(options)
  } else {
    bootbox.prompt(options)
  }
}

function tvoverlaybeAlert(_title, _message, _callback) {
  if (typeof jeeDialog !== 'undefined') {
    jeeDialog.alert({ title: _title, message: _message, callback: _callback })
  } else {
    bootbox.alert({ title: _title, message: _message, callback: _callback })
  }
}

function tvoverlaybeAjax(_action, _data, _success, _error) {
  var payload = { action: _action }
  for (var key in _data) {
    if (Object.prototype.hasOwnProperty.call(_data, key)) { payload[key] = _data[key] }
  }
  domUtils.ajax({
    type: 'POST',
    url: 'plugins/tvoverlaybe/core/ajax/tvoverlaybe.ajax.php',
    data: payload,
    dataType: 'json',
    global: false,
    error: function (error) {
      if (typeof _error === 'function') { _error(error); return }
      domUtils.handleAjaxError(error)
    },
    success: function (result) {
      if (result.state !== 'ok') {
        if (typeof _error === 'function') { _error(result); return }
        jeedomUtils.showAlert({ message: tvoverlaybeEscape(result.result), level: 'danger' })
        return
      }
      _success(result)
    }
  })
}

/* Valeur d'attribut HTML : l'échappement par innerHTML ne couvre pas les
   guillemets, qui fermeraient l'attribut. */
function tvoverlaybeAttr(_text) {
  return String((_text === null || _text === undefined) ? '' : _text)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#39;')
}

function tvoverlaybeFailed(_error, _default) {
  domUtils.hideLoading()
  jeedomUtils.showAlert({ message: tvoverlaybeEscape((_error && _error.result) ? _error.result : _default), level: 'danger' })
}

function tvoverlaybeCurrentId() {
  var id = document.querySelector('.eqLogicAttr[data-l1key="id"]')
  return (id === null) ? '' : id.value
}

function tvoverlaybeReload(_id) {
  jeedomUtils.loadPage('index.php?v=d&m=tvoverlaybe&p=tvoverlaybe' + (_id ? '&id=' + _id : ''))
}

function tvoverlaybeText(_id, _text) {
  var el = tvoverlaybeEl(_id)
  if (el !== null) { el.textContent = (_text === null || _text === undefined) ? '' : String(_text) }
}

/* ================================================================== AJOUT */

function tvoverlaybeCandidates() {
  domUtils.showLoading()
  tvoverlaybeAjax('candidates', {}, function (result) {
    domUtils.hideLoading()
    tvoverlaybeChoose(result.result)
  }, function (error) {
    tvoverlaybeFailed(error, '{{Échec de la recherche}}')
  })
}

/* « 192.168.0.106 » ou « 192.168.0.106:5002 ». */
function tvoverlaybeAddIp() {
  tvoverlaybePrompt('{{Adresse IP de la TV (et port, si ce n\'est pas 5001)}}', '', '192.168.0.106', function (_address) {
    var parts = String(_address).trim().split(':')
    var ip = parts[0]
    var port = parts.length > 1 ? parseInt(parts[1], 10) : 5001
    if (ip === '') { return }
    domUtils.showLoading()
    tvoverlaybeAjax('probe', { ip: ip, port: port }, function (result) {
      domUtils.hideLoading()
      var d = result.result
      tvoverlaybeChoose([{ ip: d.ip, port: d.port, name: d.name, overlay: true, version: d.version, known: d.known }])
    }, function (error) {
      domUtils.hideLoading()
      /* TV éteinte ou appli arrêtée : c'est justement le cas que le plugin
         sait traiter. On peut créer la TV quand même. */
      var message = (error && error.result) ? String(error.result) : '{{TvOverlay ne répond pas à cette adresse}}'
      tvoverlaybeConfirm('{{TvOverlay ne répond pas}}', '<p>' + tvoverlaybeEscape(message) + '</p><p>{{Créer la TV quand même ? Son état sera lu dès que TvOverlay répondra.}}</p>', function () {
        domUtils.showLoading()
        tvoverlaybeAjax('create', { entries: JSON.stringify([{ ip: ip, port: port, force: 1 }]) }, function (result) {
          domUtils.hideLoading()
          if (result.result.errors && result.result.errors.length > 0) {
            jeedomUtils.showAlert({ message: tvoverlaybeEscape(result.result.errors.join(' ; ')), level: 'danger' })
            return
          }
          tvoverlaybeReload(result.result.created[0])
        }, function (error2) {
          tvoverlaybeFailed(error2, '{{Échec de la création}}')
        })
      })
    })
  })
}

function tvoverlaybeChoose(_entries) {
  var entries = Array.isArray(_entries) ? _entries : []
  if (entries.length === 0) {
    jeedomUtils.showAlert({ message: '{{Aucune TV dans le plugin Google TV. Ajoutez la TV par son adresse IP.}}', level: 'warning' })
    return
  }
  var html = '<p>{{Cochez les TV à créer. TvOverlay doit y répondre.}}</p>'
  /* Les choix sont retenus à chaque clic : jeeDialog retire la fenêtre du
     document avant d'appeler le rappel, les cases n'y sont plus lisibles. */
  window.tvoverlaybeChosen = {}
  for (var i = 0; i < entries.length; i++) {
    var e = entries[i]
    window.tvoverlaybeChosen[i] = e.overlay && !e.known
    html += '<div class="checkbox"><label>'
    html += '<input type="checkbox" class="tvoverlaybeFound" data-index="' + i + '"' + (window.tvoverlaybeChosen[i] ? ' checked' : '') + (e.overlay ? '' : ' disabled') + '> '
    html += '<b>' + tvoverlaybeEscape(e.name) + '</b> — ' + tvoverlaybeEscape(e.ip)
    if (e.overlay) {
      html += ' <span class="label label-success">TvOverlay ' + tvoverlaybeEscape(e.version || '') + '</span>'
    } else {
      html += ' <span class="label label-warning" title="' + tvoverlaybeAttr(e.error || '') + '">{{TvOverlay ne répond pas}}</span>'
    }
    if (e.known) {
      html += ' <span class="label label-default">{{déjà créée :}} ' + tvoverlaybeEscape(e.known) + '</span>'
    }
    html += '</label></div>'
  }
  tvoverlaybeConfirm('{{TV}}', html, function () {
    var chosen = []
    for (var index in window.tvoverlaybeChosen) {
      if (window.tvoverlaybeChosen[index]) {
        var entry = entries[parseInt(index, 10)]
        chosen.push({ ip: entry.ip, port: entry.port || 5001, name: entry.name })
      }
    }
    if (chosen.length === 0) {
      jeedomUtils.showAlert({ message: '{{Aucune TV cochée : rien n\'a été créé.}}', level: 'warning' })
      return
    }
    domUtils.showLoading()
    tvoverlaybeAjax('create', { entries: JSON.stringify(chosen) }, function (result) {
      domUtils.hideLoading()
      var r = result.result
      if (r.errors && r.errors.length > 0) {
        var list = r.errors.map(function (_e) { return '<li>' + tvoverlaybeEscape(_e) + '</li>' }).join('')
        tvoverlaybeAlert('{{Création incomplète}}', '<p>' + r.created.length + ' {{TV créée(s). Échecs :}}</p><ul>' + list + '</ul>', function () { tvoverlaybeReload() })
        return
      }
      tvoverlaybeReload(r.created.length === 1 ? r.created[0] : '')
    }, function (error) {
      tvoverlaybeFailed(error, '{{Échec de la création}}')
    })
  })
}

/* ================================================ INDICATEURS AUTOMATIQUES */

function tvoverlaybeMarkModified() {
  if (typeof jeeFrontEnd !== 'undefined') { jeeFrontEnd.modifyWithoutSave = true }
  window.modifyWithoutSave = true
}

/* Les champs qui n'ont de sens que pour un choix (« Visible si… », texte
   issu d'une commande…) : data-if="clé=valeur". Cachés, ils gardent leur
   valeur : repasser en « Fixe » puis revenir ne fait rien perdre. */
function tvoverlaybeAutoToggle(_panel) {
  _panel.querySelectorAll('.tvoAutoIf').forEach(function (_el) {
    var rule = _el.getAttribute('data-if').split('=')
    var field = _panel.querySelector('.tvoAutoAttr[data-key="' + rule[0] + '"]')
    _el.style.display = (field !== null && field.value === rule[1]) ? '' : 'none'
  })
}

function tvoverlaybeAutoAddCond(_panel, _condition) {
  var template = tvoverlaybeEl('tpl_tvoverlaybeAutoCond')
  var body = _panel.querySelector('.tvoAutoConds tbody')
  if (template === null || body === null) { return }
  var row = template.content.firstElementChild.cloneNode(true)
  var condition = _condition || {}
  row.querySelectorAll('.tvoCondAttr').forEach(function (_field) {
    var key = _field.getAttribute('data-key')
    if (isset(condition[key]) && condition[key] !== null) { _field.value = condition[key] }
  })
  body.appendChild(row)
}

function tvoverlaybeAutoAdd(_indicator) {
  var template = tvoverlaybeEl('tpl_tvoverlaybeAuto')
  var root = tvoverlaybeEl('div_tvoverlaybeAuto')
  if (template === null || root === null) { return }
  var panel = template.content.firstElementChild.cloneNode(true)
  var indicator = _indicator || {}
  panel.querySelectorAll('.tvoAutoAttr').forEach(function (_field) {
    var key = _field.getAttribute('data-key')
    if (_field.type === 'checkbox') {
      _field.checked = !isset(indicator[key]) || String(indicator[key]) !== '0'
    } else if (isset(indicator[key]) && indicator[key] !== null) {
      _field.value = indicator[key]
    }
  })
  var conditions = Array.isArray(indicator.conditions) ? indicator.conditions : []
  conditions.forEach(function (_c) { tvoverlaybeAutoAddCond(panel, _c) })
  root.appendChild(panel)
  tvoverlaybeAutoToggle(panel)
  return panel
}

function tvoverlaybeAutoRead() {
  var list = []
  document.querySelectorAll('#div_tvoverlaybeAuto .tvoAuto').forEach(function (_panel) {
    var indicator = {}
    _panel.querySelectorAll('.tvoAutoAttr').forEach(function (_field) {
      indicator[_field.getAttribute('data-key')] = (_field.type === 'checkbox') ? (_field.checked ? 1 : 0) : _field.value
    })
    indicator.conditions = []
    _panel.querySelectorAll('.tvoAutoCond').forEach(function (_row) {
      var condition = {}
      _row.querySelectorAll('.tvoCondAttr').forEach(function (_field) {
        condition[_field.getAttribute('data-key')] = _field.value
      })
      /* Une ligne laissée vide n'est pas une condition. */
      if (String(condition.cmd || '').trim() !== '') { indicator.conditions.push(condition) }
    })
    list.push(indicator)
  })
  return list
}

/* Choisir une commande info : le champ reçoit son nom lisible
   (#[Objet][Équipement][Commande]#), que le coeur convertit en #id# à
   l'enregistrement. */
function tvoverlaybeAutoPick(_button) {
  var group = _button.closest('.input-group')
  var field = (group === null) ? null : group.querySelector('input')
  if (field === null) { return }
  jeedom.cmd.getSelectModal({ cmd: { type: 'info' } }, function (_result) {
    if (!_result || !_result.human) { return }
    field.value = _result.human
    tvoverlaybeMarkModified()
  })
}

/* Appelée par plugin.template.js avant l'enregistrement. */
function saveEqLogic(_eqLogic) {
  if (!isset(_eqLogic.configuration)) { _eqLogic.configuration = {} }
  _eqLogic.configuration.auto_fixed = tvoverlaybeAutoRead()
  return _eqLogic
}

/* =============================================================== ÉQUIPEMENT */

function tvoverlaybeRender(_data) {
  var text = ''
  if (!_data.loading) {
    if (!_data.googletv_plugin) {
      text = '{{Plugin Google TV absent ou inactif : TvOverlay ne sera pas relancée.}}'
    } else if (!_data.googletv) {
      text = '{{Aucune TV Google TV associée : TvOverlay ne sera pas relancée.}}'
    } else if (String(_data.relaunch) !== '1') {
      text = '{{Relance désactivée (case ci-dessus).}}'
    } else {
      text = '{{Relance par}} ' + _data.googletv
    }
  }
  tvoverlaybeText('span_tvoverlaybeGoogleTv', text)
  var raw = tvoverlaybeEl('pre_tvoverlaybeRaw')
  if (raw !== null) {
    raw.textContent = _data.raw ? JSON.stringify(_data.raw, null, 2) : '{{Rien lu pour le moment.}}'
  }
}

function printEqLogic(_eqLogic) {
  var root = tvoverlaybeEl('div_tvoverlaybeAuto')
  if (root !== null) { root.innerHTML = '' }
  var configuration = (isset(_eqLogic) && isset(_eqLogic.configuration)) ? _eqLogic.configuration : {}
  var auto = Array.isArray(configuration.auto_fixed) ? configuration.auto_fixed : []
  auto.forEach(function (_indicator) { tvoverlaybeAutoAdd(_indicator) })
  tvoverlaybeRender({ loading: true })
  if (isset(_eqLogic.id) && _eqLogic.id !== '') {
    var id = String(_eqLogic.id)
    tvoverlaybeAjax('data', { id: id }, function (result) {
      if (String(result.result.id) !== String(tvoverlaybeCurrentId())) { return }
      tvoverlaybeRender(result.result)
    })
  }
}

function tvoverlaybeAction(_action, _done) {
  var id = tvoverlaybeCurrentId()
  if (id === '') { return }
  /* Une relance de TvOverlay par le plugin Google TV prend une dizaine de
     secondes. */
  domUtils.showLoading()
  tvoverlaybeAjax(_action, { id: id }, function (result) {
    domUtils.hideLoading()
    if (result.result && result.result.raw !== undefined) { tvoverlaybeRender(result.result) }
    jeedomUtils.showAlert({ message: _done, level: 'success' })
  }, function (error) {
    tvoverlaybeFailed(error, '{{TvOverlay ne répond pas}}')
  })
}

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) {
    var _cmd = { configuration: {} }
  }
  if (!isset(_cmd.configuration)) {
    _cmd.configuration = {}
  }

  var tr = '<td>'
  /* Sans ce champ, chaque enregistrement détruit puis recrée les commandes. */
  tr += '<span class="cmdAttr" data-l1key="id" style="display:none;"></span>'
  tr += '<div class="input-group">'
  tr += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name" placeholder="{{Nom}}">'
  tr += '<span class="input-group-btn">'
  tr += '<a class="cmdAction btn btn-sm btn-default" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a>'
  tr += '</span>'
  tr += '<span class="cmdAttr input-group-addon roundedRight" data-l1key="display" data-l2key="icon" style="font-size:19px;padding:0 5px 0 0!important;"></span>'
  tr += '</div>'
  tr += '</td>'
  tr += '<td>'
  tr += '<span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span>'
  tr += '<span class="subType" subType="' + init(_cmd.subType) + '"></span>'
  tr += '</td>'
  tr += '<td>'
  tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible" checked>{{Afficher}}</label>'
  if (init(_cmd.type) === 'info') {
    tr += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>'
  }
  tr += '<span class="cmdAttr" data-l1key="unite" style="margin-left:8px;opacity:.7;"></span>'
  tr += '</td>'
  tr += '<td><span class="cmdAttr" data-l1key="htmlstate"></span></td>'
  tr += '<td>'
  if (is_numeric(_cmd.id)) {
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="configure"><i class="fas fa-cogs"></i></a> '
    tr += '<a class="btn btn-default btn-xs cmdAction" data-action="test"><i class="fas fa-rss"></i> {{Tester}}</a> '
  }
  tr += '</td>'

  /* Ligne créée en DOM : insertAdjacentHTML sur une table crée un <tbody> par
     insertion. */
  var newRow = document.createElement('tr')
  newRow.innerHTML = tr
  newRow.classList.add('cmd')
  newRow.setAttribute('data-cmd_id', init(_cmd.id))
  newRow.setAttribute('title', '{{Identifiant logique}} : ' + init(_cmd.logicalId))
  document.getElementById('table_cmd').querySelector('tbody').appendChild(newRow)
  newRow.setJeeValues(_cmd, '.cmdAttr')
  jeedom.cmd.changeType(newRow, init(_cmd.subType))
}

/* =============================================================== ÉCOUTEURS */

/* Pages chargées en ajax : DOMContentLoaded a déjà eu lieu, et ce script est
   réexécuté à chaque visite de la page. Les gestionnaires sont redéfinis à
   chaque chargement ; les écouteurs, eux, ne sont posés qu'une fois sur le
   document et les appellent au moment de l'événement. */
window.tvoverlaybeHandlers = {
  click: function (_event) {
    var target = _event.target
    if (target === null || typeof target.closest !== 'function') { return }
    var actions = {
      bt_tvoverlaybeCandidates: tvoverlaybeCandidates,
      bt_tvoverlaybeAddIp: tvoverlaybeAddIp,
      bt_tvoverlaybeTest: function () { tvoverlaybeAction('test', '{{Notification envoyée.}}') },
      bt_tvoverlaybeRefresh: function () { tvoverlaybeAction('refresh', '{{État relu.}}') }
    }
    for (var id in actions) {
      if (target.closest('#' + id) !== null) {
        _event.preventDefault()
        actions[id]()
        return
      }
    }
    var el
    if (target.closest('#bt_tvoverlaybeAutoAdd') !== null) {
      /* Un nouvel indicateur : visible toujours, en cercle, 12 h. */
      var panel = tvoverlaybeAutoAdd({ enable: 1, visibility: 'always', text_mode: 'none', icon_mode: 'fixed', shape: 'circle', expiration: '12h' })
      if (panel) { panel.scrollIntoView({ block: 'nearest' }) }
      tvoverlaybeMarkModified()
      return
    }
    if ((el = target.closest('.tvoAutoRemove')) !== null) {
      el.closest('.tvoAuto').remove()
      tvoverlaybeMarkModified()
      return
    }
    if ((el = target.closest('.tvoAutoCondAdd')) !== null) {
      tvoverlaybeAutoAddCond(el.closest('.tvoAuto'), { operator: '==', value: '1' })
      tvoverlaybeMarkModified()
      return
    }
    if ((el = target.closest('.tvoAutoCondRemove')) !== null) {
      el.closest('.tvoAutoCond').remove()
      tvoverlaybeMarkModified()
      return
    }
    if ((el = target.closest('.tvoAutoPick')) !== null) {
      tvoverlaybeAutoPick(el)
    }
  },
  change: function (_event) {
    var box = _event.target
    if (box && box.classList && box.classList.contains('tvoverlaybeFound') && window.tvoverlaybeChosen) {
      window.tvoverlaybeChosen[box.getAttribute('data-index')] = box.checked
    }
    if (box && box.classList && (box.classList.contains('tvoAutoAttr') || box.classList.contains('tvoCondAttr'))) {
      var panel = box.closest('.tvoAuto')
      if (panel !== null) { tvoverlaybeAutoToggle(panel) }
      tvoverlaybeMarkModified()
    }
  },
  /* Frappe dans un champ d'indicateur : la page doit savoir qu'il y a
     quelque chose à enregistrer, comme pour les champs du coeur. */
  input: function (_event) {
    var field = _event.target
    if (field && field.classList && (field.classList.contains('tvoAutoAttr') || field.classList.contains('tvoCondAttr'))) {
      tvoverlaybeMarkModified()
    }
  }
}

/* Retenu par type d'événement : une version précédente du script, restée
   dans l'onglet (window.tvoverlaybeListening), a déjà posé « click » et
   « change » ; les reposer doublerait chaque clic. */
window.tvoverlaybeListeningTypes = window.tvoverlaybeListeningTypes ||
  (window.tvoverlaybeListening ? { click: true, change: true } : {})
;['click', 'change', 'input'].forEach(function (_type) {
  if (window.tvoverlaybeListeningTypes[_type]) { return }
  window.tvoverlaybeListeningTypes[_type] = true
  document.addEventListener(_type, function (_event) {
    if (window.tvoverlaybeHandlers && typeof window.tvoverlaybeHandlers[_type] === 'function') {
      window.tvoverlaybeHandlers[_type](_event)
    }
  })
})
window.tvoverlaybeListening = true
