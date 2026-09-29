# Changelog

## 0.2.0

- Indicateurs automatiques : onglet de l'équipement (clé `auto_fixed`),
  sans scénario. Visibilité toujours ou selon des conditions (OU / ET),
  texte fixe ou issu d'une commande (arrondi, suffixe), icône fixe ou issue
  d'une commande, couleurs, forme, expiration renouvelée (12 h par défaut).
  Envoi seulement quand l'affichage change, retrait quand il devient
  invisible, republication au démarrage de Jeedom, au retour de TvOverlay
  et à l'allumage de la TV ; jamais de relance de TvOverlay pour eux.
- Retirer un indicateur / tous les indicateurs : un indicateur automatique
  retiré reste retiré jusqu'à ce que son contenu change.

## 0.1.0

- Première version : notifications, indicateurs fixes (id obligatoire,
  liste gardée en base, retrait global), retrait d'une notification par son
  id, réglages de TvOverlay, état relu chaque minute, relance par le plugin
  Google TV (jamais TV en veille).
- Widget dédié : état, envoi d'une notification, indicateurs retirables
  d'un clic, interrupteurs et curseurs, mise à jour en direct.
