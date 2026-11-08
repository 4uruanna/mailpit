# Partie 3 — Test de bout en bout

> Avant de commencer : les tests locaux de la [Partie 1, étape 5](01-podman.md#étape-5--démarrer-le-service) doivent passer, et les `dig` de la [Partie 2, étape 4](02-ovh-dns-zone.md#étape-4--vérifier-la-propagation) doivent renvoyer les bonnes valeurs.

1. Depuis votre messagerie habituelle (Gmail, Outlook…), envoyez un mail à `nimportequoi@test.mondomaine.fr`.
2. Suivez son arrivée sur le serveur :
   ```bash
   sudo journalctl -u mailpit -f
   ```
3. Il doit apparaître dans l'interface web (http://localhost:8025 via le [tunnel SSH](01-podman.md#étape-7--accéder-à-linterface-web)).

> ⚠️ **Tester depuis chez vous avec `telnet`/`nc` sur le port 25 ne prouve rien.** La plupart des box françaises bloquent le port 25 **sortant**. Le test peut donc échouer même si tout est bien configuré. Envoyer un vrai mail depuis Gmail est le test fiable.

---

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| `swaks` local OK, mais rien n'arrive depuis Gmail | Vérifier les `dig` (DNS pas encore propagé ?), le pare-feu (avec ufw, il faut la règle `ufw route`, voir [Partie 1, étape 6](01-podman.md#étape-6--ouvrir-le-port-25-dans-le-pare-feu) ; pare-feu réseau OVH) et que la cible du MX a bien un enregistrement A |
| Serveur à la maison : rien n'arrive | Redirection du port 25 sur la box, IP locale qui a changé (bail DHCP statique ?), port 25 entrant bloqué par l'opérateur. Voir [Serveur à la maison](01-podman.md#serveur-à-la-maison-derrière-une-box) |
| Gmail renvoie « recipient rejected » | L'adresse ne correspond pas à `MP_SMTP_ALLOWED_RECIPIENTS` (faute de frappe dans le domaine ?) |
| Rien n'arrive et le service est arrêté | Voir le [dépannage de la Partie 1](01-podman.md#dépannage) |

---

[←](02-ovh-dns-zone.md) · [Sommaire](../mailpit.md) · [→](04-tls.md)