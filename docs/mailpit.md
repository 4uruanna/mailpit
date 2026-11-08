# Mailpit sur Debian avec Podman + réception des mails via un domaine OVH

Ce tuto installe **Mailpit** sur un serveur Debian avec **Podman**, en service systemd qui démarre au boot. Il configure ensuite la **zone DNS OVH** pour que les e-mails envoyés à `*@test.mondomaine.fr` arrivent dans Mailpit. Enfin, il active le **chiffrement TLS** avec un certificat Let's Encrypt (Certbot).

```
Expéditeur (Gmail, une appli…)
        │  lit le MX de test.mondomaine.fr
        ▼
mail.mondomaine.fr : port 25  ──►  Podman  ──►  Mailpit (port 1025 dans le conteneur)
                                                   │
Vous ── tunnel SSH ──► 127.0.0.1:8025  ◄───────────┘  (interface web)
```

> ⚠️ **Mailpit est un outil de test, pas une vraie messagerie.** Il n'a pas d'antispam ni de boîtes séparées : quiconque accède à l'interface voit tous les mails. Il ne renvoie jamais les mails ailleurs (ce n'est donc pas un « open relay »). Utilisez-le pour recevoir des mails de test, pas votre courrier personnel.

---

## Sommaire

1. [Partie 1 — Installer Mailpit avec Podman](./mailpit/01-podman.md) : installation, configuration, service systemd (Quadlet), accès à l'interface web
2. [Partie 2 — Configurer la zone DNS chez OVH](./mailpit/02-ovh-dns-zone.md) : enregistrements A et MX
3. [Partie 3 — Test de bout en bout](./mailpit/03-test.md) : envoi d'un vrai mail et dépannage
4. [Partie 4 — Activer le TLS (STARTTLS) avec Certbot](./mailpit/04-tls.md) : certificat Let's Encrypt et renouvellement automatique
5. [Partie 5 — Maintenance](./mailpit/05-maintenance.md) : mises à jour, surveillance du certificat, sauvegardes, vérifications régulières

Suivez les parties **dans l'ordre** : la Partie 4 a besoin de l'enregistrement DNS créé en Partie 2.

---

## Avant de commencer

**Remplacez partout dans ce tuto :**

| Valeur d'exemple | À remplacer par |
|---|---|
| `mondomaine.fr` | votre nom de domaine |
| `test.mondomaine.fr` | le sous-domaine qui recevra les mails de test |
| `mail.mondomaine.fr` | le nom de votre serveur (pour le MX) |
| `IP_DU_SERVEUR` | l'adresse IPv4 publique du serveur |
| `admin:MotDePasseSolide` | vos identifiants pour l'interface web |
| `vous@exemple.fr` | votre vraie adresse (compte Let's Encrypt : avis importants sur le service) |

> 💡 Le sous-domaine qui reçoit les mails et le nom du serveur **peuvent être le même**. Exemple : recevoir sur `*@mail.mondomaine.fr`, avec un MX `mail` qui pointe vers `mail.mondomaine.fr.` lui-même. Remplacez alors `test.mondomaine.fr` **et** `mail.mondomaine.fr` par ce seul nom dans tout le tuto.
>
> Inutile d'avoir déjà un « nom de serveur » : il suffit d'une IP fixe. Le nom est créé en Partie 2 par l'enregistrement A.

**Prérequis :**

- **Debian 13 (Trixie)**. Elle fournit Podman 5.4 avec **Quadlet**, la méthode utilisée ici pour créer le service systemd. Vérifiez avec `cat /etc/debian_version` (doit afficher `13.x`). Sur Debian 12, voir l'[annexe de la Partie 1](./mailpit/01-podman.md#annexe--debian-12).
- Un accès `root` ou `sudo`.
- Une IP publique fixe : VPS, serveur dédié, ou machine chez vous derrière une box avec IP fixe (voir la [redirection de port en Partie 1](./mailpit/01-podman.md#serveur-à-la-maison-derrière-une-box)).
- Un domaine dont la **zone DNS est gérée chez OVH**.

> 💡 **Pourquoi un sous-domaine ?** Si vous avez déjà de vraies adresses sur `mondomaine.fr` (MX Plan, Email Pro…), changer le MX du domaine principal enverrait **tout** votre courrier dans Mailpit. Avec `test.mondomaine.fr`, vos vraies adresses ne sont pas touchées. La [variante « domaine principal »](./mailpit/02-ovh-dns-zone.md#variante--utiliser-le-domaine-principal-mondomainefr) est décrite en Partie 2 si c'est vraiment ce que vous voulez.

---

[Commencer : Partie 1 — Installer Mailpit avec Podman →](./mailpit/01-podman.md)