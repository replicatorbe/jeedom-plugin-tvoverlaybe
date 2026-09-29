<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
$plugin = plugin::byId('tvoverlaybe');
sendVarToJS('eqType', $plugin->getId());
$eqLogics = eqLogic::byType($plugin->getId());
$googleTvs = tvoverlaybe::googleTvAvailable() ? eqLogic::byType('googletvbe') : array();
?>

<div class="row row-overflow">
	<div class="col-xs-12 eqLogicThumbnailDisplay">
		<legend><i class="fas fa-cog"></i> {{Gestion}}</legend>
		<div class="eqLogicThumbnailContainer">
			<?php if (tvoverlaybe::googleTvAvailable()) { ?>
			<div class="cursor logoPrimary" id="bt_tvoverlaybeCandidates">
				<i class="fas fa-tv"></i>
				<br>
				<span>{{Ajouter depuis Google TV}}</span>
			</div>
			<?php } ?>
			<div class="cursor <?php echo tvoverlaybe::googleTvAvailable() ? 'logoSecondary' : 'logoPrimary'; ?>" id="bt_tvoverlaybeAddIp">
				<i class="fas fa-plus-circle"></i>
				<br>
				<span>{{Ajouter par adresse IP}}</span>
			</div>
			<div class="cursor eqLogicAction logoSecondary" data-action="gotoPluginConf">
				<i class="fas fa-wrench"></i>
				<br>
				<span>{{Configuration}}</span>
			</div>
		</div>

		<legend><i class="fas fa-comment-alt"></i> {{Mes TV}}</legend>
		<?php
		if (count($eqLogics) === 0) {
			echo '<div class="alert alert-info" style="margin:5px;">';
			echo '<b>{{Aucune TV pour le moment. Pour démarrer :}}</b>';
			echo '<ol style="margin:5px 0 0 0;padding-left:20px;">';
			echo '<li>{{Installez l\'appli TvOverlay sur la TV (Play Store), ouvrez-la une fois et accordez-lui l\'affichage par-dessus les autres applis.}}</li>';
			echo '<li>{{Cliquez sur « Ajouter depuis Google TV » : les TV du plugin Google TV où TvOverlay répond sont proposées. Sinon, saisissez l\'adresse IP de la TV.}}</li>';
			echo '<li>{{Sans le plugin Google TV, les notifications fonctionnent, mais TvOverlay n\'est pas relancée quand Android l\'arrête.}}</li>';
			echo '</ol>';
			echo '</div>';
		}
		echo '<div class="input-group" style="margin:5px;">';
		echo '<input class="form-control roundedLeft" placeholder="{{Rechercher}}" id="in_searchEqlogic">';
		echo '<div class="input-group-btn">';
		echo '<a id="bt_resetSearch" class="btn" style="width:30px"><i class="fas fa-times"></i></a>';
		echo '<a class="btn roundedRight hidden" id="bt_pluginDisplayAsTable" data-coreSupport="1" data-state="0"><i class="fas fa-grip-lines"></i></a>';
		echo '</div>';
		echo '</div>';
		echo '<div class="eqLogicThumbnailContainer">';
		foreach ($eqLogics as $eqLogic) {
			$opacity = ($eqLogic->getIsEnable()) ? '' : 'disableCard';
			echo '<div class="eqLogicDisplayCard cursor ' . $opacity . '" data-eqLogic_id="' . $eqLogic->getId() . '">';
			echo '<img src="' . $plugin->getPathImgIcon() . '">';
			echo '<br>';
			echo '<span class="name">' . $eqLogic->getHumanName(true, true) . '</span>';
			echo '<span class="hiddenAsCard displayTableRight hidden">';
			echo '<span class="label label-default">' . htmlspecialchars((string) $eqLogic->getConfiguration('ip', '')) . '</span> ';
			echo ($eqLogic->getIsVisible() == 1) ? '<i class="fas fa-eye" title="{{Equipement visible}}"></i>' : '<i class="fas fa-eye-slash" title="{{Equipement non visible}}"></i>';
			echo '</span>';
			echo '</div>';
		}
		echo '</div>';
		?>
	</div>

	<div class="col-xs-12 eqLogic" style="display: none;">
		<div class="input-group pull-right" style="display:inline-flex">
			<span class="input-group-btn">
				<a class="btn btn-default btn-sm eqLogicAction roundedLeft" data-action="configure"><i class="fas fa-cogs"></i><span class="hidden-xs"> {{Configuration avancée}}</span></a>
				<a class="btn btn-sm btn-success eqLogicAction" data-action="save"><i class="fas fa-check-circle"></i> {{Sauvegarder}}</a>
				<a class="btn btn-sm btn-danger eqLogicAction roundedRight" data-action="remove"><i class="fas fa-minus-circle"></i> {{Supprimer}}</a>
			</span>
		</div>
		<ul class="nav nav-tabs" role="tablist">
			<li role="presentation"><a href="#" class="eqLogicAction" aria-controls="home" role="tab" data-toggle="tab" data-action="returnToThumbnailDisplay"><i class="fas fa-arrow-circle-left"></i></a></li>
			<li role="presentation" class="active"><a href="#eqlogictab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-tachometer-alt"></i><span class="hidden-xs"> {{Équipement}}</span></a></li>
			<li role="presentation"><a href="#autotab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-magic"></i><span class="hidden-xs"> {{Indicateurs automatiques}}</span></a></li>
			<li role="presentation"><a href="#helptab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-book"></i><span class="hidden-xs"> {{Exemples}}</span></a></li>
			<li role="presentation"><a href="#diagtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-stethoscope"></i><span class="hidden-xs"> {{Diagnostic}}</span></a></li>
			<li role="presentation"><a href="#commandtab" aria-controls="home" role="tab" data-toggle="tab"><i class="fas fa-list"></i><span class="hidden-xs"> {{Commandes}}</span></a></li>
		</ul>

		<div class="tab-content">
			<!-- ========================= ÉQUIPEMENT ========================= -->
			<div role="tabpanel" class="tab-pane active" id="eqlogictab">
				<br>
				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tag"></i> {{Général}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Nom}}</label>
								<div class="col-sm-6">
									<input type="text" class="eqLogicAttr form-control" data-l1key="id" style="display:none;">
									<input type="text" class="eqLogicAttr form-control" data-l1key="name" placeholder="{{TV du salon}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Objet parent}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="object_id">
										<option value="">{{Aucun}}</option>
										<?php
										foreach ((jeeObject::buildTree(null, false)) as $object) {
											echo '<option value="' . $object->getId() . '">' . $object->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Catégorie}}</label>
								<div class="col-sm-8">
									<?php
									foreach (jeedom::getConfiguration('eqLogic:category') as $key => $value) {
										echo '<label class="checkbox-inline">';
										echo '<input type="checkbox" class="eqLogicAttr" data-l1key="category" data-l2key="' . $key . '">' . $value['name'];
										echo '</label>';
									}
									?>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Activer}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isEnable" checked>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Visible}}</label>
								<div class="col-sm-8">
									<input type="checkbox" class="eqLogicAttr" data-l1key="isVisible" checked>
								</div>
							</div>
						</fieldset>
					</form>
				</div>

				<div class="col-lg-6">
					<form class="form-horizontal">
						<fieldset>
							<legend><i class="fas fa-tv"></i> {{TvOverlay}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Adresse IP}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="ip" placeholder="192.168.0.106">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Port}}</label>
								<div class="col-sm-3">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="port" placeholder="5001">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Relancer si arrêtée}}</label>
								<div class="col-sm-9">
									<input type="checkbox" class="eqLogicAttr" data-l1key="configuration" data-l2key="relaunch">
									<span class="help-block" style="margin:0;">{{Une notification qui trouve TvOverlay arrêtée la fait relancer par le plugin Google TV (appairé à cette TV), puis part. La surveillance régulière, elle, se règle dans l'équipement Google TV.}}</span>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{TV Google TV}}</label>
								<div class="col-sm-6">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="googletv">
										<option value="">{{Automatique (même adresse IP)}}</option>
										<?php
										foreach ($googleTvs as $googleTv) {
											echo '<option value="' . $googleTv->getId() . '">' . $googleTv->getHumanName(true, true) . '</option>';
										}
										?>
									</select>
									<span class="help-block" style="margin:0;"><span id="span_tvoverlaybeGoogleTv"></span></span>
								</div>
							</div>
						</fieldset>
						<fieldset>
							<legend><i class="fas fa-sliders-h"></i> {{Valeurs par défaut des notifications}}</legend>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Durée}}</label>
								<div class="col-sm-3">
									<input type="number" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="default_duration" placeholder="{{de l'appli}}" min="1" max="300">
								</div>
								<div class="col-sm-5"><span class="help-block" style="margin:0;">{{secondes}}</span></div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Coin}}</label>
								<div class="col-sm-5">
									<select class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="default_corner">
										<option value="">{{Celui de l'appli}}</option>
										<?php
										foreach (tvoverlaybe::CORNERS as $key => $label) {
											echo '<option value="' . $key . '">' . $label . '</option>';
										}
										?>
									</select>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label">{{Petite icône}}</label>
								<div class="col-sm-5">
									<input type="text" class="eqLogicAttr form-control" data-l1key="configuration" data-l2key="default_icon" placeholder="mdi:home-automation">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-3 control-label"></label>
								<div class="col-sm-9">
									<span class="help-block">{{Appliquées quand la notification ne les précise pas.}}</span>
									<a class="btn btn-default btn-sm" id="bt_tvoverlaybeTest"><i class="fas fa-comment-alt"></i> {{Envoyer une notification de test}}</a>
									<a class="btn btn-default btn-sm" id="bt_tvoverlaybeRefresh"><i class="fas fa-sync"></i> {{Relire l'état}}</a>
								</div>
							</div>
						</fieldset>
					</form>
				</div>
			</div>

			<!-- =================== INDICATEURS AUTOMATIQUES ================== -->
			<!-- Une liste, que data-l2key ne sait pas ramasser : saveEqLogic()
			     la lit et la range dans configuration.auto_fixed. -->
			<div role="tabpanel" class="tab-pane" id="autotab">
				<br>
				<div class="col-xs-12">
					<div class="alert alert-info">
						{{Des indicateurs qui s'affichent et se retirent seuls, sans scénario : le plugin suit les commandes choisies et n'envoie à la TV que ce qui change à l'écran. Chacun est renvoyé avant son expiration, et republié quand Jeedom redémarre, quand TvOverlay est relancée ou quand la TV se rallume.}}
						<br>
						{{L'id est obligatoire et unique : TvOverlay ne permet de retirer un indicateur que par son id. Un indicateur retiré à la main (« Retirer un indicateur », « Retirer tous les indicateurs », croix du widget) reste retiré jusqu'à ce que ce qu'il affiche change.}}
					</div>
					<a class="btn btn-success btn-sm" id="bt_tvoverlaybeAutoAdd"><i class="fas fa-plus-circle"></i> {{Ajouter un indicateur}}</a>
					<br><br>
					<div id="div_tvoverlaybeAuto"></div>
				</div>
				<!-- Modèles copiés par le JS, jamais envoyés tels quels. -->
				<template id="tpl_tvoverlaybeAuto">
					<div class="panel panel-default tvoAuto">
						<div class="panel-heading">
							<div class="form-inline">
								<label class="checkbox-inline" title="{{Actif}}"><input type="checkbox" class="tvoAutoAttr" data-key="enable" checked> {{Actif}}</label>
								&nbsp;
								<input type="text" class="form-control input-sm tvoAutoAttr" data-key="id" placeholder="{{id (obligatoire), ex. meteo}}" style="width:170px;">
								<input type="text" class="form-control input-sm tvoAutoAttr" data-key="name" placeholder="{{Nom, ex. Météo}}" style="width:220px;">
								<a class="btn btn-danger btn-sm pull-right tvoAutoRemove" title="{{Supprimer cet indicateur}}"><i class="fas fa-minus-circle"></i></a>
							</div>
						</div>
						<div class="panel-body form-horizontal">
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Visibilité}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm tvoAutoAttr" data-key="visibility">
										<option value="always">{{Toujours}}</option>
										<option value="conditions">{{Visible si…}}</option>
									</select>
								</div>
								<div class="col-sm-4 tvoAutoIf" data-if="visibility=conditions">
									<select class="form-control input-sm tvoAutoAttr" data-key="combine">
										<option value="any">{{au moins une condition (OU)}}</option>
										<option value="all">{{toutes les conditions (ET)}}</option>
									</select>
								</div>
							</div>
							<div class="form-group tvoAutoIf" data-if="visibility=conditions">
								<div class="col-sm-offset-2 col-sm-10">
									<table class="table table-condensed tvoAutoConds" style="margin-bottom:5px;">
										<tbody></tbody>
									</table>
									<a class="btn btn-default btn-xs tvoAutoCondAdd"><i class="fas fa-plus"></i> {{Ajouter une condition}}</a>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Texte}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm tvoAutoAttr" data-key="text_mode">
										<option value="none">{{Aucun}}</option>
										<option value="fixed">{{Fixe}}</option>
										<option value="cmd">{{Valeur d'une commande}}</option>
									</select>
								</div>
								<div class="col-sm-4 tvoAutoIf" data-if="text_mode=fixed">
									<input type="text" class="form-control input-sm tvoAutoAttr" data-key="text" placeholder="{{Salon}}">
								</div>
								<div class="col-sm-4 tvoAutoIf" data-if="text_mode=cmd">
									<div class="input-group">
										<input type="text" class="form-control input-sm roundedLeft tvoAutoAttr" data-key="text_cmd" placeholder="#[Maison][Météo][Température]#">
										<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight tvoAutoPick" data-target="text_cmd" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
									</div>
								</div>
								<div class="col-sm-3 tvoAutoIf" data-if="text_mode=cmd">
									<div class="input-group">
										<input type="number" min="0" max="6" class="form-control input-sm roundedLeft tvoAutoAttr" data-key="decimals" placeholder="{{décimales}}" title="{{Arrondi : nombre de décimales (vide = valeur telle quelle)}}">
										<input type="text" class="form-control input-sm roundedRight tvoAutoAttr" data-key="suffix" placeholder="{{suffixe, ex. °}}" title="{{Ajouté après la valeur}}">
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Icône}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm tvoAutoAttr" data-key="icon_mode">
										<option value="fixed">{{Fixe}}</option>
										<option value="cmd">{{Valeur d'une commande}}</option>
									</select>
								</div>
								<div class="col-sm-4 tvoAutoIf" data-if="icon_mode=cmd">
									<div class="input-group">
										<input type="text" class="form-control input-sm roundedLeft tvoAutoAttr" data-key="icon_cmd" placeholder="#[Maison][Météo][Icône]#">
										<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight tvoAutoPick" data-target="icon_cmd" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
									</div>
								</div>
								<div class="col-sm-3">
									<input type="text" class="form-control input-sm tvoAutoAttr" data-key="icon" placeholder="mdi:lightbulb" title="{{Icône fixe ; avec une commande, icône de repli tant qu'elle n'a rien publié}}">
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Couleurs}}</label>
								<div class="col-sm-10">
									<div class="form-inline">
										<input type="text" class="form-control input-sm tvoAutoAttr" data-key="iconColor" placeholder="{{icône #ff9800}}" style="width:130px;">
										<input type="text" class="form-control input-sm tvoAutoAttr" data-key="messageColor" placeholder="{{texte #ffffff}}" style="width:130px;">
										<input type="text" class="form-control input-sm tvoAutoAttr" data-key="borderColor" placeholder="{{bordure #ff9800}}" style="width:130px;">
										<input type="text" class="form-control input-sm tvoAutoAttr" data-key="backgroundColor" placeholder="{{fond #66000000}}" style="width:130px;">
									</div>
								</div>
							</div>
							<div class="form-group">
								<label class="col-sm-2 control-label">{{Forme}}</label>
								<div class="col-sm-3">
									<select class="form-control input-sm tvoAutoAttr" data-key="shape">
										<option value="">{{Celle de l'appli}}</option>
										<option value="circle">{{Cercle}}</option>
										<option value="rounded">{{Arrondie}}</option>
										<option value="rectangular">{{Rectangle}}</option>
									</select>
								</div>
								<label class="col-sm-2 control-label">{{Expiration}}</label>
								<div class="col-sm-2">
									<input type="text" class="form-control input-sm tvoAutoAttr" data-key="expiration" placeholder="12h">
								</div>
								<div class="col-sm-3"><span class="help-block" style="margin:0;">{{Renouvelée avant terme (30m, 12h, 1d…). 12h par défaut.}}</span></div>
							</div>
						</div>
					</div>
				</template>
				<template id="tpl_tvoverlaybeAutoCond">
					<tr class="tvoAutoCond">
						<td>
							<div class="input-group">
								<input type="text" class="form-control input-sm roundedLeft tvoCondAttr" data-key="cmd" placeholder="#[Salon][Lampe][Etat]#">
								<span class="input-group-btn"><a class="btn btn-default btn-sm roundedRight tvoAutoPick" title="{{Choisir une commande}}"><i class="fas fa-list-alt"></i></a></span>
							</div>
						</td>
						<td style="width:90px;">
							<select class="form-control input-sm tvoCondAttr" data-key="operator">
								<option value="==">==</option>
								<option value="!=">!=</option>
								<option value="&gt;">&gt;</option>
								<option value="&gt;=">&gt;=</option>
								<option value="&lt;">&lt;</option>
								<option value="&lt;=">&lt;=</option>
							</select>
						</td>
						<td style="width:140px;"><input type="text" class="form-control input-sm tvoCondAttr" data-key="value" placeholder="1"></td>
						<td style="width:40px;"><a class="btn btn-danger btn-sm tvoAutoCondRemove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a></td>
					</tr>
				</template>
			</div>

			<!-- =========================== EXEMPLES ========================== -->
			<div role="tabpanel" class="tab-pane" id="helptab">
				<br>
				<div class="col-xs-12">
					<p>{{Dans un scénario, les commandes « … (JSON) » prennent en message un objet JSON. Les champs sont ceux de l'API de TvOverlay.}}</p>
					<legend>{{Notifier (JSON)}}</legend>
					<p>{{Champs : title, message, source, image, video, largeIcon, smallIcon, smallIconColor, corner (top_end, top_start, bottom_end, bottom_start), duration, id. Icônes : mdi:nom, adresse d'image ou base64. Vidéo : RTSP, HLS, DASH.}}</p>
					<pre>{"id":"sonnette","title":"On sonne","message":"Porte d'entrée","video":"rtsp://user:motdepasse@192.168.0.50:554/cam/realmonitor?channel=1&amp;subtype=1","corner":"top_end","duration":30}</pre>
					<pre>{"title":"Lessive terminée","smallIcon":"mdi:washing-machine","smallIconColor":"#2196f3","duration":15}</pre>
					<legend>{{Retirer une notification}}</legend>
					<p>{{Message : l'id de la notification, par exemple}} <code>sonnette</code>{{. Le plugin la renvoie avec le même contenu pour une seconde, et elle s'efface : TvOverlay n'a pas d'autre moyen de retirer une notification.}}</p>
					<p>{{Seules les notifications envoyées par « Notifier (JSON) » avec un id sont retirables (les 30 dernières, oubliées au redémarrage de Jeedom). Une notification déjà terminée n'est pas renvoyée : rien ne réapparaît.}}</p>
					<legend>{{Indicateur (JSON)}}</legend>
					<p><b>{{L'id est obligatoire.}}</b> {{TvOverlay n'offre aucun autre moyen de retirer un indicateur ni de lister ceux qui sont affichés : sans id, il resterait jusqu'à son expiration, ou pour toujours. Le plugin retient les id qu'il affiche (info « Indicateurs affichés ») ; « Retirer tous les indicateurs » les retire tous — pas ceux qu'un autre système aurait envoyés.}}</p>
					<p>{{Champs : id, message, icon, iconColor, messageColor, borderColor, backgroundColor, shape (circle, rounded, rectangular), expiration (1h, 30m, 12h… ou secondes), visible.}}</p>
					<pre>{"id":"meteo","icon":"mdi:weather-rainy","message":"14°","shape":"circle","expiration":"12h"}</pre>
					<pre>{"id":"lampe","icon":"mdi:lightbulb","iconColor":"#ff9800","borderColor":"#ff9800","shape":"circle"}</pre>
					<p>{{Un indicateur qui doit suivre des commandes (météo, lampes allumées) n'a pas besoin de scénario : onglet « Indicateurs automatiques ».}}</p>
					<legend>{{Retirer un indicateur}}</legend>
					<p>{{Message : l'id de l'indicateur, par exemple}} <code>lampe</code>.</p>
				</div>
			</div>

			<!-- ========================== DIAGNOSTIC ========================= -->
			<div role="tabpanel" class="tab-pane" id="diagtab">
				<br>
				<div class="col-xs-12">
					<legend><i class="fas fa-code"></i> {{Dernier état lu sur TvOverlay}}</legend>
					<pre id="pre_tvoverlaybeRaw" style="max-height:520px;overflow:auto;"></pre>
				</div>
			</div>

			<!-- ========================== COMMANDES ========================== -->
			<div role="tabpanel" class="tab-pane" id="commandtab">
				<br>
				<div class="col-xs-12">
					<table id="table_cmd" class="table table-bordered table-condensed">
						<thead>
							<tr>
								<th style="width:300px;">{{Nom}}</th>
								<th style="width:130px;">{{Type}}</th>
								<th>{{Paramètres}}</th>
								<th style="width:160px;">{{Valeur}}</th>
								<th style="width:120px;">{{Actions}}</th>
							</tr>
						</thead>
						<tbody></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>

<?php include_file('desktop', 'tvoverlaybe', 'js', 'tvoverlaybe'); ?>
<?php include_file('core', 'plugin.template', 'js'); ?>
