# Partie 2 — Configurer la zone DNS chez OVH

> Remplacez les valeurs d'exemple (`mondomaine.fr`, `IP_DU_SERVEUR`…) comme indiqué dans [Avant de commencer](../mailpit.md#avant-de-commencer).

Il faut **deux enregistrements** :

| Type | Sous-domaine | Cible | Rôle |
|---|---|---|---|
| **A** | `mail` | `IP_DU_SERVEUR` | donne l'adresse IP de votre serveur |
| **MX** | `test` | `mail.mondomaine.fr.` (priorité 10) | dit « les mails pour `@test.mondomaine.fr` vont à ce serveur » |

## Étape 1 — Ouvrir la zone DNS

1. Connectez-vous à l'**espace client OVHcloud**.
2. Allez dans **Web Cloud** → **Noms de domaine** → cliquez sur `mondomaine.fr`.
3. Ouvrez l'onglet **Zone DNS**.

## Étape 2 — Ajouter l'enregistrement A

> ⚠️ **Vérifiez d'abord qu'aucune entrée n'existe déjà pour `mail`.** La zone par défaut d'OVH contient souvent un **CNAME** `mail` → `ssl0.ovh.net.` (webmail OVH). Un nom qui a un CNAME ne peut avoir **aucun** autre enregistrement : OVH refusera le A, ou le MX ne fonctionnera pas. Dans la zone, cherchez `mail` dans le filtre. Si vous n'utilisez pas le webmail OVH, supprimez ce CNAME (**…** → **Supprimer l'entrée**). Sinon, prenez un autre nom (`mx`, `mailpit`…).

1. Cliquez sur **Ajouter une entrée** → choisissez **A**.
2. **Sous-domaine** : `mail`
3. **Cible** : `IP_DU_SERVEUR`
4. TTL : laissez la valeur par défaut, puis validez.

> Si un enregistrement A pointe déjà vers ce serveur (par ex. `vps.mondomaine.fr`), vous pouvez le réutiliser comme cible du MX au lieu de créer `mail`.
>
> Évitez d'ajouter un enregistrement **AAAA** (IPv6) pour `mail`, sauf si vous avez vérifié que Mailpit répond aussi en IPv6. Sinon, certains expéditeurs essaieront l'IPv6 et échoueront.

## Étape 3 — Ajouter l'enregistrement MX

1. Cliquez sur **Ajouter une entrée** → choisissez **MX**.
2. **Sous-domaine** : `test`
3. **Priorité** : `10`
4. **Cible** : `mail.mondomaine.fr.` (**avec le point final**)
5. TTL par défaut, puis validez.

> ⚠️ La cible d'un MX doit être un **nom d'hôte** qui a un enregistrement A. Jamais une adresse IP, ni un CNAME.

## Variante : utiliser le domaine principal (`@mondomaine.fr`)

À ne faire **que** si vous n'avez aucune vraie adresse e-mail sur ce domaine, ou si vous acceptez de ne plus les recevoir :

1. Dans la zone DNS, filtrez sur le type **MX**. Supprimez les entrées existantes (`mx0.mail.ovh.net.`, `mx1.mail.ovh.net.`, etc.) avec le bouton **…** → **Supprimer l'entrée**.
2. Ajoutez un MX avec le **sous-domaine vide**, priorité `10` et la cible `mail.mondomaine.fr.`
3. Dans `/etc/mailpit/mailpit.env` (créé en [Partie 1, étape 3](01-podman.md#étape-3--créer-le-fichier-de-configuration-de-mailpit)), remplacez la règle par `MP_SMTP_ALLOWED_RECIPIENTS=@mondomaine\.fr$`, puis lancez `sudo systemctl restart mailpit`.

## Étape 4 — Vérifier la propagation

Une modification de zone DNS peut mettre de quelques minutes à 24 h pour être visible partout. Depuis n'importe quelle machine Linux ou Mac (paquet `bind9-dnsutils` sous Debian) :

```bash
dig +short A mail.mondomaine.fr     # doit afficher IP_DU_SERVEUR
dig +short MX test.mondomaine.fr    # doit afficher : 10 mail.mondomaine.fr.
```

> Pas besoin de SPF, DKIM, DMARC ni de reverse DNS (PTR) : ils servent à **envoyer** des mails, pas à en recevoir.

---

[←](01-podman.md) · [Sommaire](../mailpit.md) · [→](03-test.md)