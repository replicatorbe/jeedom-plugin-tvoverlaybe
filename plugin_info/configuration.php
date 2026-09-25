<?php
if (!isConnect('admin')) {
	throw new Exception('{{401 - Accès non autorisé}}');
}
?>
<form class="form-horizontal">
	<fieldset>
		<div class="alert alert-info">
			{{Rien à régler ici : chaque TV se règle dans son équipement. L'appli TvOverlay doit être installée sur la TV (Play Store). Avec le plugin Google TV, TvOverlay est relancée automatiquement quand Android l'arrête.}}
		</div>
	</fieldset>
</form>
