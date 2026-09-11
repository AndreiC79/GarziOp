# Backup automat pe serverul spitalului

Creati din Administrare un token tehnic cu dreptul `admin.reports`, numele `Backup server spital` si expirare lunga. Tokenul ramane numai pe serverul de backup.

Endpoint: `/api/backup-export.php?period=daily&date=2026-08-17`. `period` poate fi `daily`, `monthly` sau `annual`; antetul este `Authorization: Bearer TOKENUL_SECRET`.

```powershell
$headers = @{ Authorization = "Bearer TOKENUL_SECRET" }
$date = Get-Date -Format "yyyy-MM-dd"
Invoke-WebRequest "https://intern.maternitateagiulesti.ro/api/backup-export.php?period=daily&date=$date" -Headers $headers -OutFile "D:\BackupIntern\intern_daily_$date.json"
```

Programati scriptul zilnic. Lunar rulati pentru luna precedenta, iar anual pentru anul precedent. Exportul cuprinde operatii normale, EXTRA, urgente, statusul efectuat, garzile publicate si logurile intervalului. Fiecare acces si export este jurnalizat.
