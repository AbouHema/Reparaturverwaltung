<div class="results-bar">
    <p class="result-count">
        <strong data-result-count><?= h(count($auftraege)) ?></strong>
        <?= count($auftraege) === 1 ? "Auftrag" : "Aufträge" ?>
        <?= $suche !== "" ? "gefunden" : "insgesamt" ?>
    </p>
    <?php if ($suche !== ""): ?>
        <span class="muted">Suche: „<?= h($suche) ?>“</span>
    <?php endif; ?>
</div>

<?php if ($auftraege !== []): ?>
    <div class="orders-list">
        <?php foreach ($auftraege as $auftrag): ?>
            <?php
            $auftragId = (int) $auftrag["id"];
            $geraete = $geraeteNachAuftrag[$auftragId] ?? [];
            $anzahlGeraete = count($geraete);
            ?>
            <article class="glass-panel order-card" data-order-card data-order-id="<?= h($auftragId) ?>">
                <header class="order-card-header">
                    <div class="order-summary">
                        <span class="order-number">#<?= h($auftragId) ?></span>
                        <div>
                            <h2><?= h($auftrag["name"]) ?></h2>
                            <?php if (!empty($auftrag["firmenname"])): ?>
                                <p class="order-company"><?= h($auftrag["firmenname"]) ?></p>
                            <?php endif; ?>
                            <?php if(adresse_zeilen($auftrag)!==[]):?><p class="order-company"><?= h(implode(" · ",adresse_zeilen($auftrag))) ?></p><?php endif;?>
                            <p class="order-meta">
                                <?php if (!empty($auftrag["telefon"])): ?>
                                    <span><?= h($auftrag["telefon"]) ?></span>
                                <?php endif; ?>
                                <?php if (!empty($auftrag["email"])): ?>
                                    <span><?= h($auftrag["email"]) ?></span>
                                <?php endif; ?>
                                <span><?= h($anzahlGeraete) ?> <?= $anzahlGeraete === 1 ? "Gerät" : "Geräte" ?></span>
                                <span>Status: <span data-order-status><?= h($auftrag["gesamtstatus"]) ?></span></span>
                                <time datetime="<?= h($auftrag["erstellt_am"]) ?>"><?= h($auftrag["erstellt_am"]) ?></time>
                            </p>
                        </div>
                    </div>
                    <div class="order-card-actions">
                        <dl class="order-amounts">
                            <div><dt>Gesamtbetrag</dt><dd data-order-total><?= betrag_anzeigen($auftrag["gesamtpreis"]) ?></dd></div>
                            <div><dt>Insgesamt bezahlt</dt><dd data-order-paid><?= betrag_anzeigen($auftrag["bereits_bezahlt"]) ?></dd></div>
                            <div class="order-amounts__rest"><dt>Gesamter Restbetrag</dt><dd data-order-rest><?= betrag_anzeigen($auftrag["restbetrag"]) ?></dd></div>
                        </dl>
                        <?php $direkteZuordnung = $anzahlGeraete === 1 ? (int) $geraete[0]["zuordnung_id"] : null; ?>
                        <a class="button button--secondary button--compact" href="abholschein.php?auftrag_id=<?= h($auftragId) ?><?= $direkteZuordnung ? "&amp;geraete[]=" . h($direkteZuordnung) : "" ?>" <?= $anzahlGeraete > 1 ? 'data-document-select data-document-type="abholschein" data-order-id="' . h($auftragId) . '"' : "" ?>>Abholschein drucken</a>
                        <a class="button button--secondary button--compact" href="rechnung.php?auftrag_id=<?= h($auftragId) ?><?= $direkteZuordnung ? "&amp;geraete[]=" . h($direkteZuordnung) : "" ?>" <?= $anzahlGeraete > 1 ? 'data-document-select data-document-type="rechnung" data-order-id="' . h($auftragId) . '"' : "" ?>>
                            Rechnung erstellen / anzeigen
                        </a>
                        <a class="button button--secondary button--compact" href="bearbeiten.php?id=<?= h($auftragId) ?>">
                            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 20 4.2-1 10.5-10.5a2.1 2.1 0 0 0-3-3L5.2 16 4 20Z"/><path d="m14.5 6.5 3 3"/></svg>
                            Bearbeiten
                        </a>
                        <?php if (darf_loeschen()): ?>
                            <button
                                class="button button--danger button--compact"
                                type="button"
                                data-delete-order
                                data-confirm-form="auftrag-loeschen-<?= h($auftragId) ?>"
                                data-confirm-message="<?= (int) $auftrag["hat_rechnung"] === 1
                                    ? "Möchten Sie diesen Reparaturauftrag wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden. Die vorhandene Rechnung bleibt aus Aufbewahrungsgründen erhalten."
                                    : "Möchten Sie diesen Reparaturauftrag wirklich löschen? Diese Aktion kann nicht rückgängig gemacht werden." ?>"
                                data-confirm-label="Auftrag löschen"
                            >Auftrag löschen</button>
                        <?php endif; ?>
                    </div>
                </header>

                <?php if ($geraete !== []): ?>
                    <div class="device-overview-list">
                        <?php foreach ($geraete as $geraet): ?>
                            <?php
                            $geraetZusatz = array_values(array_filter([
                                $geraet["hersteller"] ?? null,
                                $geraet["modell"] ?? null,
                            ], static fn(mixed $wert): bool => $wert !== null && $wert !== ""));
                            $geraetTitel = $geraet["geraetetyp"]
                                . ($geraetZusatz !== [] ? " · " . implode(" · ", $geraetZusatz) : "");
                            ?>
                            <section class="device-overview-card" data-device-row data-device-id="<?= h($geraet["zuordnung_id"]) ?>" aria-labelledby="device-<?= h($geraet["zuordnung_id"]) ?>-title">
                                <div class="device-overview-main">
                                    <div>
                                        <p class="device-kicker">
                                            <?= h(geraete_referenz($auftragId, (int) $geraet["id"])) ?>
                                            <?php if (!empty($geraet["seriennummer"])): ?> · SN <?= h($geraet["seriennummer"]) ?><?php endif; ?>
                                        </p>
                                        <h3 id="device-<?= h($geraet["zuordnung_id"]) ?>-title" title="<?= h($geraetTitel) ?>"><?= h($geraetTitel) ?></h3>
                                    </div>
                                    <span class="price"><?= betrag_anzeigen($geraet["preis"]) ?></span>
                                </div>
                                <p class="device-issue"><?= h($geraet["fehlerbeschreibung"]) ?></p>
                                <?php if (!empty($geraet["durchgefuehrte_arbeiten"])): ?>
                                    <p class="device-work"><strong>Arbeiten:</strong> <?= nl2br(h($geraet["durchgefuehrte_arbeiten"])) ?></p>
                                <?php endif; ?>
                                <div class="device-financial-state">
                                    <span>Gerätepreis <?= betrag_anzeigen($geraet["preis"]) ?> · Bereits bezahlt <span data-device-paid-display><?= betrag_anzeigen($geraet["bereits_bezahlt"]) ?></span> · Restbetrag <span data-device-rest-display><?= betrag_anzeigen($geraet["restbetrag"]) ?></span> · <strong data-device-payment-status><?= h(zahlungsstatus_fuer_betraege($geraet["preis"],$geraet["bereits_bezahlt"])) ?></strong></span>
                                    <span><?= $geraet["abgeholt_am"] ? "Abgeholt " . h((new DateTimeImmutable($geraet["abgeholt_am"]))->format("d.m.Y H:i")) : "Nicht abgeholt" ?></span>
                                    <?php if ($geraet["rechnung_id"]): ?><a href="rechnung.php?rechnung_id=<?= h($geraet["rechnung_id"]) ?>">Rechnung <?= h($geraet["rechnungsnummer"]) ?></a><?php else: ?><span>Nicht abgerechnet</span><?php endif; ?>
                                </div>
                                <div class="device-overview-actions">
                                    <?php if(zahlungsstatus_fuer_betraege($geraet["preis"],$geraet["bereits_bezahlt"])!=="Bezahlt"):?><button class="button button--secondary button--compact" type="button" data-payment-trigger data-order-id="<?= h($auftragId) ?>" data-device-id="<?= h($geraet["zuordnung_id"]) ?>" data-device-label="<?= h($geraetTitel) ?>" data-device-price="<?= h($geraet["preis"]) ?>" data-device-open="<?= h($geraet["restbetrag"]) ?>">Als vollständig bezahlt markieren</button><?php endif;?>
                                    <form class="status-form" method="post" action="status_aendern.php" data-ajax-status>
                                        <?= csrf_feld() ?>
                                        <input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>">
                                        <input type="hidden" name="zuordnung_id" value="<?= h($geraet["zuordnung_id"]) ?>">
                                        <div class="status-control <?= h(status_klasse($geraet["status"])) ?>" data-status-control>
                                            <label class="visually-hidden" for="status-<?= h($geraet["zuordnung_id"]) ?>">Status für <?= h($geraet["geraetetyp"]) ?></label>
                                            <select id="status-<?= h($geraet["zuordnung_id"]) ?>" name="status_id">
                                                <?php foreach ($status as $eintrag): ?>
                                                    <option value="<?= h($eintrag["id"]) ?>" <?= (int) $eintrag["id"] === (int) $geraet["status_id"] ? "selected" : "" ?>><?= h($eintrag["bezeichnung"]) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <button class="button button--secondary button--compact" type="submit">Status ändern</button>
                                    </form>
                                    <?php if (darf_loeschen()): ?>
                                        <button
                                            class="button button--ghost-danger button--compact"
                                            type="button"
                                            data-confirm-form="geraet-loeschen-<?= h($geraet["zuordnung_id"]) ?>"
                                            data-confirm-message="Möchten Sie dieses Gerät wirklich aus dem Reparaturauftrag löschen? Diese Aktion kann nicht rückgängig gemacht werden."
                                            data-confirm-label="Gerät löschen"
                                            data-last-device="<?= $anzahlGeraete === 1 ? "true" : "false" ?>"
                                            data-order-delete-form="auftrag-loeschen-<?= h($auftragId) ?>"
                                            data-edit-url="bearbeiten.php?id=<?= h($auftragId) ?>#geraete"
                                        >Gerät löschen</button>
                                    <?php endif; ?>
                                </div>
                            </section>
                            <form id="geraet-loeschen-<?= h($geraet["zuordnung_id"]) ?>" method="post" action="geraet_loeschen.php" hidden>
                                <?= csrf_feld() ?>
                                <input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>">
                                <input type="hidden" name="zuordnung_id" value="<?= h($geraet["zuordnung_id"]) ?>">
                            </form>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="inline-empty-state">
                        <p>Dieser Auftrag enthält kein Gerät. Fügen Sie sofort ein Gerät hinzu oder löschen Sie den Auftrag.</p>
                        <a class="button button--primary button--compact" href="bearbeiten.php?id=<?= h($auftragId) ?>#geraete">Gerät hinzufügen</a>
                    </div>
                <?php endif; ?>
            </article>
            <form id="auftrag-loeschen-<?= h($auftragId) ?>" method="post" action="auftrag_loeschen.php" data-ajax-delete-order hidden>
                <?= csrf_feld() ?>
                <input type="hidden" name="auftrag_id" value="<?= h($auftragId) ?>">
            </form>
        <?php endforeach; ?>
    </div>
<?php elseif ($suche !== ""): ?>
    <div class="glass-panel empty-state">
        <span class="empty-state-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        </span>
        <h2>Keine passenden Reparaturaufträge gefunden</h2>
        <p class="muted">Ändern oder leeren Sie den Suchbegriff, um andere Aufträge anzuzeigen.</p>
    </div>
<?php else: ?>
    <div class="glass-panel empty-state">
        <span class="empty-state-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/></svg>
        </span>
        <h2>Noch keine Reparaturaufträge</h2>
        <p class="muted">Legen Sie den ersten Reparaturauftrag an.</p>
        <a class="button button--primary" href="index.php">Neuer Auftrag</a>
    </div>
<?php endif; ?>
