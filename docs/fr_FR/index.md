# Plugin TvOverlay

Affiche les notifications de Jeedom sur les téléviseurs **Android TV / Google
TV**, par-dessus l'image, sans interrompre ce qui passe. Il s'appuie sur
l'appli **TvOverlay** (`com.tabdeveloper.tvoverlay`, Play Store), qui expose
une API HTTP sur la TV (port 5001). Tout reste sur le réseau local.

## Installation

1. Sur la TV : installez TvOverlay depuis le Play Store, ouvrez-la une fois et
   accordez-lui l'affichage par-dessus les autres applis.
2. Dans Jeedom : **Ajouter depuis Google TV** propose les TV du plugin Google
   TV où TvOverlay répond ; **Ajouter par adresse IP** sinon (`adresse:port`
   si le port n'est pas 5001). Une TV dont TvOverlay ne répond pas peut être
   créée quand même : son état sera lu au premier contact.

## Avec le plugin Google TV : TvOverlay toujours en marche

Android arrête TvOverlay de temps à autre, et une notification envoyée
pendant ce temps se perd. Le plugin **Google TV** (`googletvbe`), appairé à la
même TV, s'en charge :

- il surveille TvOverlay et la relance par la télécommande (fiche Play Store,
  « Ouvrir », Retour, puis la touche Lecture pour reprendre un film mis en
  pause) — réglage dans l'équipement Google TV ;
- une notification de ce plugin qui trouve TvOverlay arrêtée lui demande
  aussitôt une relance, puis repart (case « Relancer si arrêtée »).

L'association se fait par l'adresse IP, ou se choisit dans l'équipement.

## Widget

Sur le dashboard, la TV a son propre widget, mis à jour en direct :

- l'état : TvOverlay en ligne, écran allumé ;
- **Envoyer à la TV** : un titre, un message, un bouton ;
- les **indicateurs affichés**, chacun retirable d'un clic, et « tout
  retirer » ;
- les interrupteurs **Notifications** et **Indicateurs**, les curseurs
  **Horloge** et **Fond**.

Les commandes JSON n'y figurent pas : elles sont faites pour les scénarios.

## Commandes

| Commande | Type | Rôle |
|---|---|---|
| En ligne | info | TvOverlay répond |
| Écran allumé | info | Selon TvOverlay ; valable seulement quand « En ligne » vaut 1 |
| Notifications actives, Indicateurs actifs | info | Leur affichage est activé dans TvOverlay |
| Horloge, Fond | info | Visibilité, 0 à 95 % |
| Durée des notifications | info | Secondes (réglage de l'appli) |
| Coin de l’overlay, Autorisation d’affichage, Optimisation de batterie, Version de TvOverlay | info | Masquées par défaut |
| Notifier | action | Titre et message |
| Notifier (JSON) | action | Tous les champs de l'API (voir ci-dessous) |
| Retirer une notification | action | Message : son `id` |
| Indicateurs affichés | info | Les `id` des indicateurs envoyés par ce plugin et pas encore expirés |
| Indicateur (JSON) | action | Notification fixe dans un coin ; `id` obligatoire |
| Retirer un indicateur | action | Message : son `id` |
| Retirer tous les indicateurs | action | Ceux de la liste ci-dessus |
| Activer / Suspendre les notifications | action | |
| Afficher / Masquer les indicateurs | action | |
| Régler l’horloge, Régler le fond, Régler la durée (1 à 300 s), Choisir le coin de l’overlay | action | |

L'état est relu chaque minute, et aussitôt après un réglage.

Une notification qui trouve TvOverlay arrêtée **alors que la TV est en
veille, ou dans un état inconnu du plugin Google TV**, n'est pas envoyée, et
la relance n'a pas lieu : elle ouvrirait la fiche Play Store et pourrait
rallumer l'écran. Une relance n'est pas refaite moins de deux minutes après
la précédente.

### Notifier (JSON)

Champs : `title`, `message`, `source`, `image`, `video`, `largeIcon`,
`smallIcon`, `smallIconColor`, `corner` (`top_end`, `top_start`,
`bottom_end`, `bottom_start`), `duration`, `id`. Une icône ou une image est
un nom `mdi:…`, une adresse d'image ou du base64 ; une vidéo, un flux RTSP,
HLS ou DASH.

```json
{"id":"sonnette","title":"On sonne","message":"Porte d'entrée","video":"rtsp://user:motdepasse@192.168.0.50:554/cam/realmonitor?channel=1&subtype=1","corner":"top_end","duration":30}
```

Durée, coin et petite icône par défaut se règlent dans l'équipement.

**Retirer** : commande *Retirer une notification*, avec l'`id` en message.
TvOverlay n'a pas de commande de retrait : le plugin renvoie la notification
sous le même `id`, avec le même contenu, pour une seconde. Une mise à jour
sans texte serait ignorée (vérifié) ; c'est pourquoi le plugin retient le
contenu des 30 dernières notifications envoyées **par *Notifier (JSON)* avec
un `id`** (oubliées au redémarrage de Jeedom). Une notification déjà terminée
ou inconnue n'est pas renvoyée : rien ne réapparaît à l'écran. *Notifier*
(titre et message) n'a pas d'`id` : ses notifications ne se retirent pas.

### Indicateur (JSON)

**L'`id` est obligatoire.** TvOverlay tire un identifiant au hasard quand on
n'en donne pas, sans le renvoyer, et n'a aucun moyen de lister ni d'effacer
les indicateurs affichés : un indicateur sans `id` ne se retirerait plus
qu'à son expiration, ou jamais. Le plugin refuse donc un indicateur sans
`id`, et retient ceux qu'il affiche pour *Retirer tous les indicateurs*
(pas ceux qu'un autre système, Home Assistant par exemple, aurait envoyés).

Champs : `id`, `message`, `icon`, `iconColor`, `messageColor`,
`borderColor`, `backgroundColor`, `shape` (`circle`, `rounded`,
`rectangular`), `expiration` (`30m`, `12h`, secondes…), `visible`.

```json
{"id":"lampe","icon":"mdi:lightbulb","iconColor":"#ff9800","borderColor":"#ff9800","shape":"circle"}
```

**Retirer** : *Retirer un indicateur* avec l'`id` en message.

## Garder TvOverlay en vie sans relance

Exempter une fois TvOverlay de l'optimisation de batterie limite les arrêts :
`adb shell dumpsys deviceidle whitelist +com.tabdeveloper.tvoverlay`.
