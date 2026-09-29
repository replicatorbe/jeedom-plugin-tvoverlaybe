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
| Indicateurs affichés | info | Les `id` des indicateurs envoyés par ce plugin (scénarios et indicateurs automatiques) et pas encore expirés |
| Indicateur (JSON) | action | Notification fixe dans un coin ; `id` obligatoire |
| Retirer un indicateur | action | Message : son `id` |
| Retirer tous les indicateurs | action | Ceux de la liste ci-dessus ; un indicateur automatique reste retiré jusqu'à ce que son contenu change |
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

## Indicateurs automatiques

Onglet **Indicateurs automatiques** de l'équipement : des indicateurs qui
s'affichent, se mettent à jour et se retirent **seuls**, d'après des
commandes info de Jeedom, sans aucun scénario. De quoi remplacer, par
exemple, une pastille météo permanente et une ampoule « une lampe est
allumée ».

Pour chaque indicateur :

- **Actif**, **id** et **nom**. L'`id` est obligatoire et unique, pour la
  même raison qu'avec *Indicateur (JSON)* : sans lui, un indicateur ne se
  retire plus. L'enregistrement est refusé, avec un message, si un id
  manque ou est en double. N'utilisez pas le même id dans un scénario.
- **Visibilité** : *Toujours*, ou *Visible si…* une ou plusieurs
  conditions `commande opérateur valeur` (`==`, `!=`, `>`, `>=`, `<`,
  `<=`), combinées par *au moins une* (OU) ou *toutes* (ET). Deux nombres se
  comparent en nombres (`1.0 == 1`) ; sinon `==` et `!=` comparent le texte
  sans tenir compte de la casse, et les autres opérateurs sont faux. Une
  commande supprimée ou sans valeur rend sa condition fausse, même avec
  `!=`.
- **Texte** : aucun, fixe, ou la valeur d'une commande, arrondie à N
  décimales si on le demande (virgule décimale), suivie d'un suffixe
  (`°`). Un `-0` arrondi s'affiche `0`. Tant que la commande n'a pas de
  valeur, pas de texte (et pas de `°` seul).
- **Icône** : fixe (`mdi:…`), ou la valeur d'une commande qui publie un
  nom d'icône (`mdi:weather-rainy` ; `weather-rainy` est complété en
  `mdi:weather-rainy`). L'icône fixe sert alors de repli tant que la
  commande n'a rien publié.
- **Couleurs** de l'icône, du texte, de la bordure et du fond, **forme**
  (cercle, arrondie, rectangle).
- **Expiration** : `12h` par défaut, renouvelée avant terme (une heure
  avant, ou à mi-chemin pour une expiration courte ; deux minutes au
  minimum). Douze heures ne coûtent que deux envois par jour quand rien ne
  change, et si Jeedom s'arrête, un indicateur devenu faux (« lampe
  allumée ») disparaît de lui-même dans la journée au lieu de rester des
  jours. Une date fixe (epoch) n'est pas acceptée ici : elle ne se
  renouvellerait pas.

### Fonctionnement

- Le plugin écoute les commandes citées (un listener Jeedom, reconstruit à
  chaque enregistrement) et recalcule aussitôt. Il **n'envoie que si ce qui
  est affiché change** : apparition, disparition, texte, icône, couleurs. Une
  température relue à l'identique, ou `21,6` puis `21,8` arrondis tous deux à
  `22°`, ne font aucune requête.
- Devenu invisible, l'indicateur est **retiré** (`visible: false`, comme
  *Retirer un indicateur*). Supprimé ou désactivé dans l'équipement aussi.
- Les indicateurs automatiques figurent dans l'info **Indicateurs
  affichés** et dans le widget, comme les autres.
- **Republiés** quand l'écran a pu les perdre : au démarrage de Jeedom,
  quand TvOverlay répond de nouveau après un arrêt, quand l'écran se
  rallume, et quand le plugin Google TV a relancé TvOverlay ou vu la TV
  s'allumer (même entre deux relevés de la minute).
- **Aucune relance** de TvOverlay pour un indicateur automatique, à la
  différence d'une notification : un indicateur n'est pas une alerte, et une
  température qui change ne doit pas ouvrir la fiche Play Store par-dessus
  un film. TvOverlay arrêtée ou TV en veille : l'indicateur attend, et part
  dès que TvOverlay répond (relancée par la surveillance du plugin Google
  TV, ou TV rallumée). La TV n'est jamais réveillée.

### Retrait à la main

*Retirer un indicateur*, *Retirer tous les indicateurs* et la croix du
widget retirent aussi les indicateurs automatiques. Un indicateur
automatique retiré ainsi **reste retiré tant que ce qu'il affiche ne change
pas** — ni le renouvellement, ni une relance de TvOverlay ne le remettent.
Il revient dès que son contenu change (la température passe de 22° à 23°),
ou quand il redevient visible après avoir été caché (toutes les lampes
éteintes, puis une rallumée). « Tout retirer » pendant un film ne voit donc
pas la météo revenir à la minute, mais la lampe qu'on rallume se montre de
nouveau. Pour tout masquer longtemps sans rien perdre, *Masquer les
indicateurs*.

### Configuration de l'équipement (API)

La liste est rangée dans la configuration de l'équipement, clé
`auto_fixed`. Par l'API JSON-RPC (`eqLogic::save`), les commandes s'écrivent
`#id#` ; la page, elle, montre leur nom lisible. Tous les champs :

```json
{
  "enable": 1,
  "id": "meteo",
  "name": "Météo",
  "visibility": "always",
  "combine": "any",
  "conditions": [{"cmd": "#123#", "operator": "==", "value": "1"}],
  "text_mode": "cmd",
  "text": "",
  "text_cmd": "#456#",
  "decimals": "0",
  "suffix": "°",
  "icon_mode": "cmd",
  "icon": "mdi:weather-cloudy",
  "icon_cmd": "#789#",
  "iconColor": "",
  "messageColor": "",
  "borderColor": "",
  "backgroundColor": "",
  "shape": "circle",
  "expiration": "12h"
}
```

`visibility` : `always` ou `conditions` ; `combine` : `any` (OU) ou `all`
(ET) ; `text_mode` : `none`, `fixed` (texte `text`) ou `cmd` ;
`decimals` : vide pour la valeur telle quelle ; `icon_mode` : `fixed` ou
`cmd` ; `shape` : `circle`, `rounded`, `rectangular`, ou vide pour celle de
l'appli. Les champs absents prennent ces valeurs par défaut.

### Exemple 1 : météo

Température arrondie et icône de la commande « Icône » du plugin météo,
toujours visible :

```json
{"enable":1,"id":"meteo","name":"Météo","visibility":"always",
 "text_mode":"cmd","text_cmd":"#[Maison][Météo][Température]#","decimals":"0","suffix":"°",
 "icon_mode":"cmd","icon_cmd":"#[Maison][Météo][Icône]#","icon":"mdi:weather-cloudy",
 "shape":"circle","expiration":"12h"}
```

### Exemple 2 : une lampe est allumée

Ampoule orange tant qu'au moins une des trois lampes est allumée :

```json
{"enable":1,"id":"lampe","name":"Lampes","visibility":"conditions","combine":"any",
 "conditions":[{"cmd":"#[Salon][Lampe 1][Etat]#","operator":"==","value":"1"},
               {"cmd":"#[Salon][Lampe 2][Etat]#","operator":"==","value":"1"},
               {"cmd":"#[Cuisine][Lampe][Etat]#","operator":"==","value":"1"}],
 "text_mode":"none","icon_mode":"fixed","icon":"mdi:lightbulb",
 "iconColor":"#ff9800","borderColor":"#ff9800","shape":"circle","expiration":"12h"}
```

Par l'API, remplacez chaque `#[…]#` par l'`#id#` de la commande.

## Garder TvOverlay en vie sans relance

Exempter une fois TvOverlay de l'optimisation de batterie limite les arrêts :
`adb shell dumpsys deviceidle whitelist +com.tabdeveloper.tvoverlay`.
