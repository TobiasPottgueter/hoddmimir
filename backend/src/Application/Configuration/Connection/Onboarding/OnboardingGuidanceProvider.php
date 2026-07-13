<?php

declare(strict_types=1);

namespace App\Application\Configuration\Connection\Onboarding;

final readonly class OnboardingGuidanceProvider
{
    public function guidance(OnboardingProduct $product): OnboardingGuidance
    {
        return OnboardingProduct::Pve === $product ? $this->pve() : $this->pbs();
    }

    private function pve(): OnboardingGuidance
    {
        return new OnboardingGuidance(OnboardingProduct::Pve, [
            $this->read('pve-read-users', 'pveum user list --output-format json', 'Bestehende Benutzer prüfen; bei einem fremden gleichnamigen Benutzer anhalten.'),
            $this->read('pve-read-groups', 'pveum group list --output-format json', 'Bestehende Gruppen prüfen; bei einer fremden Gruppe Bots anhalten.'),
            $this->read('pve-read-roles', 'pveum role list --output-format json', 'Bestehende Rollen lesen und abweichende Hoddmímir-Rollen nicht überschreiben.'),
            $this->read('pve-read-tokens', 'pveum user token list hoddmimir@pve --output-format json', 'Bestehende Token-Metadaten prüfen; verlorene Secrets niemals ersetzen oder auslesen.'),
            $this->read('pve-read-acls', 'pveum acl list --output-format json', 'Bestehende ACLs und Propagation vor jeder Änderung prüfen.'),
            $this->write('pve-create-group', "pveum group add Bots --comment 'Hoddmimir runtime identities'", 'Nur ausführen, wenn die zuvor geprüfte Gruppe Bots fehlt.'),
            $this->write('pve-create-user', "pveum user add hoddmimir@pve --groups Bots --comment 'Hoddmimir runtime identity'", 'Nur ausführen, wenn der zuvor geprüfte Benutzer fehlt.'),
            $this->write('pve-create-scan-role', "pveum role add HoddmimirScan --privs 'Datastore.Audit Pool.Audit Sys.Audit VM.Audit'", 'Nur ausführen, wenn HoddmimirScan fehlt; bei abweichender Definition anhalten.'),
            $this->write('pve-create-backup-role', "pveum role add HoddmimirBackup --privs 'Datastore.AllocateSpace VM.Backup'", 'Nur ausführen, wenn HoddmimirBackup fehlt; bei abweichender Definition anhalten.'),
            $this->write('pve-group-scan-acl', 'pveum acl modify / --groups Bots --roles HoddmimirScan --propagate 1', 'Propagierte Scanner-Rolle der Gruppe am Root-Pfad idempotent setzen.'),
            $this->write('pve-group-backup-acl', 'pveum acl modify / --groups Bots --roles HoddmimirBackup --propagate 1', 'Propagierte Backup-Rolle der Gruppe am Root-Pfad idempotent setzen.'),
            $this->write('pve-create-scan-token', 'pveum user token add hoddmimir@pve scan --privsep 1', 'Nur bei fehlendem Scanner-Token ausführen; das Secret wird genau einmal ausgegeben.'),
            $this->write('pve-create-backup-token', 'pveum user token add hoddmimir@pve backup --privsep 1', 'Nur bei fehlendem Backup-Token ausführen; das Secret wird genau einmal ausgegeben.'),
            $this->write('pve-token-scan-acl', "pveum acl modify / --tokens 'hoddmimir@pve!scan' --roles HoddmimirScan --propagate 1", 'Scanner-Token ausschließlich propagiert auf HoddmimirScan begrenzen.'),
            $this->write('pve-token-backup-acl', "pveum acl modify / --tokens 'hoddmimir@pve!backup' --roles HoddmimirBackup --propagate 1", 'Backup-Token ausschließlich propagiert auf HoddmimirBackup begrenzen.'),
        ], [
            'Jeden Befehl einzeln und bewusst als Proxmox-Administrator auf genau einem Cluster-Knoten ausführen.',
            'Token-Secrets nur aus dem unmittelbaren Erzeugungsresultat übernehmen; sie können später nicht erneut gelesen werden.',
            'Keine Ausgabe in Dateien oder Shell-History-Hilfsbefehle umleiten und bei fremdem beziehungsweise abweichendem Bestand anhalten.',
        ]);
    }

    private function pbs(): OnboardingGuidance
    {
        return new OnboardingGuidance(OnboardingProduct::Pbs, [
            $this->read('pbs-read-users', 'proxmox-backup-manager user list --output-format json', 'Bestehende Benutzer prüfen; bei einem fremden gleichnamigen Benutzer anhalten.'),
            $this->read('pbs-read-tokens', 'proxmox-backup-manager user list-tokens hoddmimir@pbs --output-format json', 'Bestehende Token-Metadaten prüfen; verlorene Secrets niemals ersetzen oder auslesen.'),
            $this->read('pbs-read-acls', 'proxmox-backup-manager acl list --output-format json', 'Bestehende ACLs und Propagation vor jeder Änderung prüfen.'),
            $this->write('pbs-create-user', "proxmox-backup-manager user create hoddmimir@pbs --comment 'Hoddmimir collector identity'", 'Nur ausführen, wenn der zuvor geprüfte Benutzer fehlt.'),
            $this->write('pbs-create-token', "proxmox-backup-manager user generate-token hoddmimir@pbs scan --comment 'Hoddmimir collector token'", 'Nur bei fehlendem Scanner-Token ausführen; das Secret wird genau einmal ausgegeben.'),
            $this->write('pbs-user-system-acl', 'proxmox-backup-manager acl update /system Audit --auth-id hoddmimir@pbs --propagate true', 'System-Audit für den Basisbenutzer propagiert setzen.'),
            $this->write('pbs-user-datastore-acl', 'proxmox-backup-manager acl update /datastore DatastoreAudit --auth-id hoddmimir@pbs --propagate true', 'Datastore-Audit für den Basisbenutzer propagiert setzen.'),
            $this->write('pbs-user-remote-acl', 'proxmox-backup-manager acl update /remote RemoteAudit --auth-id hoddmimir@pbs --propagate true', 'Remote-Audit für den Basisbenutzer propagiert setzen.'),
            $this->write('pbs-token-system-acl', "proxmox-backup-manager acl update /system Audit --auth-id 'hoddmimir@pbs!scan' --propagate true", 'System-Audit für den Scanner-Token propagiert setzen.'),
            $this->write('pbs-token-datastore-acl', "proxmox-backup-manager acl update /datastore DatastoreAudit --auth-id 'hoddmimir@pbs!scan' --propagate true", 'Datastore-Audit für den Scanner-Token propagiert setzen.'),
            $this->write('pbs-token-remote-acl', "proxmox-backup-manager acl update /remote RemoteAudit --auth-id 'hoddmimir@pbs!scan' --propagate true", 'Remote-Audit für den Scanner-Token propagiert setzen.'),
        ], [
            'Jeden Befehl einzeln und bewusst direkt auf dem PBS ausführen; Hoddmímir führt keinen dieser Schreibbefehle aus.',
            'Token-Secrets nur aus dem unmittelbaren Erzeugungsresultat übernehmen; sie können später nicht erneut gelesen werden.',
            'Der PVE-zu-PBS-DatastoreBackup-Token gehört nicht in Hoddmímir und ist nicht Teil dieses Onboardings.',
        ]);
    }

    private function read(string $id, string $command, string $purpose): OnboardingGuidanceCommand
    {
        return new OnboardingGuidanceCommand($id, $command, $purpose, false);
    }

    private function write(string $id, string $command, string $purpose): OnboardingGuidanceCommand
    {
        return new OnboardingGuidanceCommand($id, $command, $purpose, true);
    }
}
