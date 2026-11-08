# Partie 3 — Test de bout en bout

> Avant de commencer : les tests locaux de la [Partie 1, étape 5](podman.md#étape-5--démarrer-le-service) doivent passer, et les `dig` de la [Partie 2, étape 4](ovh-dns-zone.md#étape-4--vérifier-la-propagation) doivent renvoyer les bonnes valeurs.

1. Depuis votre messagerie habituelle (Gmail, Outlook…), envoyez un mail à `nimportequoi@test.mondomaine.fr`.
2. Suivez son arrivée sur le serveur :
   ```bash
   sudo journalctl -u mailpit -f
   ```
3. Il doit apparaître dans l'interface web (http://localhost:8025 via le [tunnel SSH](podman.md#étape-7--accéder-à-linterface-web)).

> ⚠️ **Tester depuis chez vous avec `telnet`/`nc` sur le port 25 ne prouve rien.** La plupart des box françaises bloquent le port 25 **sortant**. Le test peut donc échouer même si tout est bien configuré. Envoyer un vrai mail depuis Gmail est le test fiable.

---

## Dépannage

| Symptôme | Cause probable / solution |
|---|---|
| `swaks` local OK, mais rien n'arrive depuis Gmail | Vérifier les `dig` (DNS pas encore propagé ?), le pare-feu (ufw, pare-feu réseau OVH) et que la cible du MX a bien un enregistrement A |
| Gmail renvoie « recipient rejected » | L'adresse ne correspond pas à `MP_SMTP_ALLOWED_RECIPIENTS` (faute de frappe dans le domaine ?) |
| Rien n'arrive et le service est arrêté | Voir le [dépannage de la Partie 1](podman.md#dépannage) |

---

[←](02-ovh-dns-zone.md) · [Sommaire](../mailpit.md) · [→](03-tls.md)