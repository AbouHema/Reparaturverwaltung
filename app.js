"use strict";

function fehlermeldungFuer(feld) {
    if (feld.validity.valueMissing) return "Bitte füllen Sie dieses Pflichtfeld aus.";
    if (feld.validity.typeMismatch) return "Bitte geben Sie einen gültigen Wert ein.";
    if (feld.validity.patternMismatch && feld.name === "telefon") return "Erlaubt sind Ziffern, +, Leerzeichen, Klammern, Bindestriche und Schrägstriche.";
    if (feld.validity.patternMismatch || feld.matches("[data-amount-input]")) return feld.validationMessage || "Bitte geben Sie einen Betrag ab 0 mit höchstens zwei Nachkommastellen ein.";
    if (feld.validity.tooLong) return `Bitte geben Sie höchstens ${feld.maxLength} Zeichen ein.`;
    return feld.validationMessage || "Bitte prüfen Sie Ihre Eingabe.";
}

function fehlerElement(feld) {
    const formularfeld = feld.closest(".form-field");
    if (!formularfeld) return null;
    let fehler = formularfeld.querySelector("[data-field-error], [data-amount-error]");
    if (!fehler) {
        fehler = document.createElement("span");
        fehler.className = "field-error";
        fehler.dataset.fieldError = "";
        fehler.hidden = true;
        const id = `${feld.id || feld.name.replace(/[^a-z0-9_-]/gi, "-")}-fehler`;
        fehler.id = id;
        formularfeld.append(fehler);
        const beschriebenDurch = new Set((feld.getAttribute("aria-describedby") || "").split(/\s+/).filter(Boolean));
        beschriebenDurch.add(id);
        feld.setAttribute("aria-describedby", [...beschriebenDurch].join(" "));
    }
    return fehler;
}

function feldzustandAktualisieren(feld, erzwingen = false) {
    const formular = feld.closest("form[data-validated-form]");
    const anzeigen = erzwingen || feld.dataset.touched === "true" || formular?.dataset.validationAttempted === "true";
    const ungueltig = anzeigen && !feld.validity.valid;
    const fehler = fehlerElement(feld);
    feld.classList.toggle("is-invalid", ungueltig);
    if (ungueltig) feld.setAttribute("aria-invalid", "true");
    else feld.removeAttribute("aria-invalid");
    if (fehler) {
        fehler.hidden = !ungueltig;
        fehler.textContent = ungueltig ? fehlermeldungFuer(feld) : "";
    }
}

const betragMuster = /^\d{1,8}(?:[,.]\d{1,2})?$/;

function betragInCent(wert, leerAlsNull = true) {
    const text = String(wert ?? "").trim();
    if (text === "") return leerAlsNull ? null : 0;
    if (!betragMuster.test(text)) return null;
    const [euro, cent = ""] = text.replace(",", ".").split(".");
    return (Number(euro) * 100) + Number(cent.padEnd(2, "0"));
}

function centFormatieren(cent) {
    return new Intl.NumberFormat("de-DE", { style: "currency", currency: "EUR" }).format(cent / 100);
}

function zahlungsuebersichtAktualisieren(formular, anzeigen = false) {
    const bereich = formular?.querySelector("[data-payment-summary]");
    if (!bereich) return;
    const preisfelder = formular.querySelectorAll("[data-device-price]");
    const nichtZugeordnetFeld = formular.querySelector("[data-unassigned-paid]");
    let gesamt = 0;
    let allePreiseGueltig = true;
    preisfelder.forEach((feld) => {
        const cent = betragInCent(feld.value);
        if (cent === null) allePreiseGueltig = false;
        else gesamt += cent;
    });
    let bezahlt = betragInCent(nichtZugeordnetFeld?.value, false) ?? 0;
    let alleZahlungenGueltig = nichtZugeordnetFeld?.value.trim() === "" || betragMuster.test(nichtZugeordnetFeld?.value.trim() || "0");
    formular.querySelectorAll("[data-device-card]").forEach((karte) => {
        const preisFeld = karte.querySelector("[data-device-price]");
        const bezahltFeld = karte.querySelector("[data-device-paid]");
        if (!bezahltFeld) return;
        const preis = betragInCent(preisFeld?.value);
        const geraetBezahlt = betragInCent(bezahltFeld.value, false);
        const syntaxGueltig = bezahltFeld.value.trim() === "" || betragMuster.test(bezahltFeld.value.trim());
        if (!syntaxGueltig) bezahltFeld.setCustomValidity("Bitte geben Sie einen Betrag ab 0 mit höchstens zwei Nachkommastellen ein.");
        else if (preis !== null && (geraetBezahlt ?? 0) > preis) bezahltFeld.setCustomValidity("Die Zahlung darf den Preis dieses Geräts nicht überschreiten.");
        else bezahltFeld.setCustomValidity("");
        alleZahlungenGueltig = alleZahlungenGueltig && bezahltFeld.validity.valid && geraetBezahlt !== null;
        bezahlt += geraetBezahlt ?? 0;
        const restAusgabe = karte.querySelector("[data-device-remaining]");
        const statusAusgabe = karte.querySelector("[data-device-payment-status]");
        if (restAusgabe) restAusgabe.textContent = preis !== null && geraetBezahlt !== null ? centFormatieren(Math.max(0, preis - geraetBezahlt)) : "–";
        if (statusAusgabe) statusAusgabe.textContent = (geraetBezahlt ?? 0) <= 0 ? "Offen" : ((preis !== null && geraetBezahlt >= preis) ? "Bezahlt" : "Teilweise bezahlt");
        feldzustandAktualisieren(bezahltFeld, anzeigen || bezahltFeld.dataset.touched === "true");
    });
    if (nichtZugeordnetFeld && nichtZugeordnetFeld.type !== "hidden") {
        if (!alleZahlungenGueltig) nichtZugeordnetFeld.setCustomValidity("Bitte geben Sie einen Betrag ab 0 mit höchstens zwei Nachkommastellen ein.");
        else if (allePreiseGueltig && bezahlt > gesamt) nichtZugeordnetFeld.setCustomValidity("Die Summe aller Zahlungen darf den Gesamtbetrag des Auftrags nicht überschreiten.");
        else nichtZugeordnetFeld.setCustomValidity("");
        feldzustandAktualisieren(nichtZugeordnetFeld, anzeigen || nichtZugeordnetFeld.dataset.touched === "true");
    }
    const gesamtAusgabe = bereich.querySelector("[data-total-amount]");
    const bezahltAusgabe = bereich.querySelector("[data-paid-amount]");
    const restAusgabe = bereich.querySelector("[data-remaining-amount]");
    if (gesamtAusgabe) gesamtAusgabe.textContent = allePreiseGueltig ? centFormatieren(gesamt) : "–";
    if (bezahltAusgabe) bezahltAusgabe.textContent = alleZahlungenGueltig ? centFormatieren(bezahlt) : "–";
    if (restAusgabe) restAusgabe.textContent = allePreiseGueltig && alleZahlungenGueltig ? centFormatieren(Math.max(0, gesamt - bezahlt)) : "–";
}

function betragsfeldVerdrahten(feld) {
    if (feld.dataset.amountWired === "true") return;
    feld.dataset.amountWired = "true";
    const pruefen = () => {
        const wert = feld.value.trim();
        const gueltig = wert === "" || betragMuster.test(wert);
        feld.setCustomValidity(gueltig ? "" : "Bitte geben Sie einen Betrag ab 0 mit höchstens zwei Nachkommastellen ein.");
        if (!gueltig) feld.dataset.touched = "true";
        zahlungsuebersichtAktualisieren(feld.closest("form"));
        feldzustandAktualisieren(feld, !gueltig);
    };
    feld.addEventListener("keydown", (event) => {
        if (["-", "+", "e", "E", "ArrowUp", "ArrowDown"].includes(event.key)) event.preventDefault();
    });
    feld.addEventListener("wheel", (event) => event.preventDefault(), { passive: false });
    feld.addEventListener("input", pruefen);
    pruefen();
}

function validierungsfeldVerdrahten(feld) {
    if (feld.dataset.validationWired === "true") return;
    feld.dataset.validationWired = "true";
    feld.addEventListener("blur", () => {
        feld.dataset.touched = "true";
        feldzustandAktualisieren(feld);
    });
    const ereignis = feld.tagName === "SELECT" ? "change" : "input";
    feld.addEventListener(ereignis, () => feldzustandAktualisieren(feld));
}

function formularfelderVerdrahten(bereich) {
    bereich.querySelectorAll("[data-amount-input]").forEach(betragsfeldVerdrahten);
    bereich.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(validierungsfeldVerdrahten);
}

document.querySelectorAll("form[data-validated-form]").forEach((formular) => {
    formularfelderVerdrahten(formular);
    zahlungsuebersichtAktualisieren(formular);
    formular.addEventListener("invalid", (event) => {
        formular.dataset.validationAttempted = "true";
        event.target.dataset.touched = "true";
        feldzustandAktualisieren(event.target, true);
    }, true);
    formular.addEventListener("submit", (event) => {
        formular.dataset.validationAttempted = "true";
        const passwort = formular.querySelector('input[name="passwort"]');
        const wiederholung = formular.querySelector('input[name="passwort_wiederholen"]');
        if (passwort && wiederholung) {
            wiederholung.setCustomValidity(passwort.value === wiederholung.value ? "" : "Die Passwörter stimmen nicht überein.");
        }
        zahlungsuebersichtAktualisieren(formular, true);
        formular.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach((feld) => feldzustandAktualisieren(feld, true));
        if (!formular.checkValidity()) event.preventDefault();
    });
});

document.querySelectorAll("[data-password-toggle]").forEach((schalter) => {
    schalter.addEventListener("change", () => {
        const formular = schalter.closest("form");
        formular?.querySelectorAll('input[type="password"], input[data-password-visible="true"]').forEach((feld) => {
            feld.type = schalter.checked ? "text" : "password";
            if (schalter.checked) feld.dataset.passwordVisible = "true";
            else delete feld.dataset.passwordVisible;
        });
    });
});

const deviceList = document.querySelector("[data-device-list]");
const deviceTemplate = document.querySelector("#device-template");
const addDeviceButton = document.querySelector("[data-add-device]");
const deviceError = document.querySelector("[data-device-list-error]");
let nextDeviceIndex = Number(deviceList?.dataset.nextIndex || 0);

function deviceCardsNummerieren() {
    deviceList?.querySelectorAll("[data-device-card]").forEach((card, index) => {
        const nummer = card.querySelector("[data-device-number]");
        if (nummer) nummer.textContent = String(index + 1);
    });
    if (deviceError) deviceError.hidden = (deviceList?.querySelectorAll("[data-device-card]").length || 0) > 0;
    zahlungsuebersichtAktualisieren(deviceList?.closest("form"));
}

addDeviceButton?.addEventListener("click", () => {
    if (!deviceTemplate || !deviceList) return;
    const html = deviceTemplate.innerHTML.replaceAll("__INDEX__", String(nextDeviceIndex++));
    deviceList.insertAdjacentHTML("beforeend", html);
    const card = deviceList.lastElementChild;
    if (card) formularfelderVerdrahten(card);
    deviceCardsNummerieren();
    card?.querySelector('input:not([type="hidden"])')?.focus();
});

deviceList?.addEventListener("click", (event) => {
    const entfernen = event.target.closest("[data-remove-unsaved-device]");
    if (!entfernen) return;
    const cards = deviceList.querySelectorAll("[data-device-card]");
    if (cards.length <= 1) {
        if (deviceError) {
            deviceError.hidden = false;
            deviceError.textContent = "Ein Reparaturauftrag muss mindestens ein Gerät enthalten.";
        }
        return;
    }
    entfernen.closest("[data-device-card]")?.remove();
    deviceCardsNummerieren();
});
deviceCardsNummerieren();

document.querySelector("[data-existing-customer]")?.addEventListener("change", (event) => {
    const option = event.target.selectedOptions[0];
    if (!option?.dataset.customer) return;
    try {
        const kunde = JSON.parse(option.dataset.customer);
        const formular = event.target.closest("form");
        for (const feld of ["name", "firmenname", "telefon", "email", "strasse", "plz", "ort", "land"]) {
            const eingabe = formular?.elements.namedItem(feld);
            if (eingabe) eingabe.value = kunde[feld] || "";
        }
    } catch (_) { meldungZeigen("Die Kundendaten konnten nicht übernommen werden.", "error"); }
});

function meldungZeigen(nachricht, typ = "success") {
    let bereich = document.querySelector("[data-toast-region]");
    if (!bereich) {
        bereich = document.createElement("div");
        bereich.className = "toast-region";
        bereich.dataset.toastRegion = "";
        bereich.setAttribute("aria-live", "polite");
        document.body.append(bereich);
    }
    const meldung = document.createElement("div");
    meldung.className = `toast toast--${typ}`;
    meldung.setAttribute("role", typ === "error" ? "alert" : "status");
    meldung.textContent = nachricht;
    bereich.append(meldung);
    window.setTimeout(() => meldung.remove(), 4500);
}

async function jsonAnfrage(formular) {
    const antwort = await fetch(formular.action, {
        method: "POST",
        body: new FormData(formular),
        headers: { "X-Requested-With": "XMLHttpRequest", "Accept": "application/json" },
    });
    let daten = {};
    try { daten = await antwort.json(); } catch (_) { /* Standardmeldung folgt. */ }
    if (!antwort.ok || !daten.ok) throw new Error(daten.fehler || "Die Änderung konnte nicht gespeichert werden. Bitte versuchen Sie es erneut.");
    return daten;
}

document.addEventListener("focusin", (event) => {
    const select = event.target.closest("form[data-ajax-status] select[name=status_id]");
    if (select && !select.dataset.committedValue) select.dataset.committedValue = select.value;
});

document.addEventListener("submit", async (event) => {
    const formular = event.target;
    if (!(formular instanceof HTMLFormElement) || !formular.matches("[data-ajax-status]")) return;
    event.preventDefault();
    if (formular.dataset.submitting === "true") return;
    const select = formular.querySelector("select[name=status_id]");
    const button = formular.querySelector('button[type="submit"]');
    const vorher = select?.dataset.committedValue || select?.value;
    formular.dataset.submitting = "true";
    if (button) button.disabled = true;
    try {
        const daten = await jsonAnfrage(formular);
        if (select) select.dataset.committedValue = select.value;
        const statusControl = formular.querySelector("[data-status-control]");
        if (statusControl) {
            statusControl.classList.remove("status--accepted", "status--progress", "status--done");
            statusControl.classList.add(daten.status_klasse);
        }
        const gesamtstatus = formular.closest("[data-order-card]")?.querySelector("[data-order-status]");
        if (gesamtstatus) gesamtstatus.textContent = daten.gesamtstatus;
        meldungZeigen(daten.nachricht || "Status erfolgreich aktualisiert.");
    } catch (fehler) {
        if (select) select.value = vorher;
        meldungZeigen(fehler.message, "error");
    } finally {
        formular.dataset.submitting = "false";
        if (button) button.disabled = false;
    }
});

const confirmDialog = document.querySelector("#confirm-dialog");
const confirmMessage = confirmDialog?.querySelector("[data-confirm-message]");
const confirmSubmit = confirmDialog?.querySelector("[data-confirm-submit]");
const lastDeviceDialog = document.querySelector("#last-device-dialog");
let pendingForm = null;
let pendingTrigger = null;

function bestaetigungOeffnen(trigger) {
    if (!confirmDialog) return;
    pendingTrigger = trigger;
    pendingForm = document.getElementById(trigger.dataset.confirmForm || "");
    if (!pendingForm) return;
    if (confirmMessage) confirmMessage.textContent = trigger.dataset.confirmMessage || "";
    if (confirmSubmit) {
        confirmSubmit.textContent = trigger.dataset.confirmLabel || "Löschen";
        confirmSubmit.disabled = false;
    }
    confirmDialog.showModal();
}

document.addEventListener("click", (event) => {
    const trigger = event.target.closest("[data-confirm-form]");
    if (!trigger) return;
    event.preventDefault();
    bestaetigungOeffnen(trigger);
});

confirmSubmit?.addEventListener("click", () => {
    if (!pendingForm || !pendingTrigger || confirmSubmit.disabled) return;
    if (pendingTrigger.dataset.lastDevice === "true" && lastDeviceDialog) {
        confirmDialog.close();
        lastDeviceDialog.dataset.orderDeleteForm = pendingTrigger.dataset.orderDeleteForm || "";
        lastDeviceDialog.dataset.editUrl = pendingTrigger.dataset.editUrl || "";
        lastDeviceDialog.showModal();
        return;
    }
    confirmSubmit.disabled = true;
    pendingForm.requestSubmit();
});

document.addEventListener("submit", async (event) => {
    const formular = event.target;
    if (!(formular instanceof HTMLFormElement) || !formular.matches("[data-ajax-delete-order]")) return;
    event.preventDefault();
    if (formular.dataset.submitting === "true") return;
    formular.dataset.submitting = "true";
    try {
        const daten = await jsonAnfrage(formular);
        const karte = document.querySelector(`[data-order-card][data-order-id="${CSS.escape(String(daten.auftrag_id))}"]`);
        karte?.remove();
        formular.remove();
        confirmDialog?.close();
        const zaehler = document.querySelector("[data-result-count]");
        if (zaehler) zaehler.textContent = String(Math.max(0, Number(zaehler.textContent) - 1));
        const liste = document.querySelector(".orders-list");
        if (liste && !liste.querySelector("[data-order-card]")) {
            liste.innerHTML = '<div class="glass-panel empty-state"><h2>Keine Reparaturaufträge auf dieser Ergebnisseite</h2><p class="muted">Passen Sie die Suche an oder wechseln Sie zur vorherigen Seite.</p></div>';
        }
        meldungZeigen(daten.nachricht || "Reparaturauftrag erfolgreich gelöscht.");
    } catch (fehler) {
        if (confirmSubmit) confirmSubmit.disabled = false;
        meldungZeigen(fehler.message, "error");
    } finally {
        formular.dataset.submitting = "false";
    }
});

document.querySelectorAll("dialog [data-dialog-cancel]").forEach((button) => {
    button.addEventListener("click", () => button.closest("dialog")?.close());
});
lastDeviceDialog?.querySelector("[data-add-last-device]")?.addEventListener("click", () => {
    const editUrl = lastDeviceDialog.dataset.editUrl;
    lastDeviceDialog.close();
    if (addDeviceButton) addDeviceButton.click();
    else if (editUrl) window.location.href = editUrl;
});
lastDeviceDialog?.querySelector("[data-delete-whole-order]")?.addEventListener("click", () => {
    const formId = lastDeviceDialog.dataset.orderDeleteForm;
    const trigger = document.querySelector(`[data-confirm-form="${CSS.escape(formId)}"][data-delete-order]`);
    lastDeviceDialog.close();
    if (trigger) bestaetigungOeffnen(trigger);
});

const paymentDialog = document.querySelector("#payment-dialog");
const fullPaymentForm = paymentDialog?.querySelector("[data-full-payment-form]");
document.addEventListener("click", (event) => {
    const trigger = event.target.closest("[data-payment-trigger]");
    if (!trigger || !paymentDialog || !fullPaymentForm) return;
    event.preventDefault();
    fullPaymentForm.elements.namedItem("auftrag_id").value = trigger.dataset.orderId;
    fullPaymentForm.elements.namedItem("zuordnung_id").value = trigger.dataset.deviceId;
    paymentDialog.querySelector("[data-payment-device]").textContent = trigger.dataset.deviceLabel;
    paymentDialog.querySelector("[data-payment-price]").textContent = centFormatieren(betragInCent(trigger.dataset.devicePrice, false) || 0);
    paymentDialog.querySelector("[data-payment-open]").textContent = centFormatieren(betragInCent(trigger.dataset.deviceOpen, false) || 0);
    fullPaymentForm.dataset.triggerDeviceId = trigger.dataset.deviceId;
    fullPaymentForm.querySelector('[name="notiz"]').value = "";
    paymentDialog.showModal();
});

fullPaymentForm?.addEventListener("submit", async (event) => {
    event.preventDefault();
    if (fullPaymentForm.dataset.submitting === "true") return;
    const submit = fullPaymentForm.querySelector('button[type="submit"]');
    fullPaymentForm.dataset.submitting = "true"; if (submit) submit.disabled = true;
    try {
        const daten = await jsonAnfrage(fullPaymentForm);
        const karte = document.querySelector(`[data-device-id="${CSS.escape(String(daten.zuordnung_id))}"]`);
        const bezahlt = karte?.querySelector("[data-device-paid-display]"); if (bezahlt) bezahlt.textContent = centFormatieren(betragInCent(daten.geraet_bezahlt, false));
        const rest = karte?.querySelector("[data-device-rest-display]"); if (rest) rest.textContent = centFormatieren(0);
        const status = karte?.querySelector("[data-device-payment-status]"); if (status) status.textContent = "Bezahlt";
        karte?.querySelector("[data-payment-trigger]")?.remove();
        const auftrag = karte?.closest("[data-order-card]");
        const gesamtBezahlt = auftrag?.querySelector("[data-order-paid]"); if (gesamtBezahlt) gesamtBezahlt.textContent = centFormatieren(betragInCent(daten.bezahlt, false));
        const gesamtRest = auftrag?.querySelector("[data-order-rest]"); if (gesamtRest) gesamtRest.textContent = centFormatieren(betragInCent(daten.rest, false));
        paymentDialog.close(); meldungZeigen(daten.nachricht);
        if (!karte?.matches("[data-device-row]")) window.location.reload();
    } catch (fehler) { meldungZeigen(fehler.message, "error"); }
    finally { fullPaymentForm.dataset.submitting = "false"; if (submit) submit.disabled = false; }
});

const liveSearchForm = document.querySelector("[data-live-search-form]");
const searchInput = liveSearchForm?.querySelector('input[type="search"]');
const searchCategory = liveSearchForm?.querySelector("[data-search-category]");
const searchClear = liveSearchForm?.querySelector("[data-search-clear]");
const searchStatus = document.querySelector("#search-status");
const searchResults = document.querySelector("#search-results");
let searchTimer = null;
let searchController = null;
let searchSequence = 0;

async function sucheAusfuehren() {
    if (!searchInput || !searchResults) return;
    const suche = searchInput.value.trim();
    const kategorie = searchCategory?.value || "alle";
    const sequence = ++searchSequence;
    searchController?.abort();
    searchController = new AbortController();
    searchResults.setAttribute("aria-busy", "true");
    if (searchStatus) searchStatus.textContent = "Suche läuft …";
    try {
        const parameter = new URLSearchParams({ suche, kategorie });
        const response = await fetch(`auftraege_suche.php?${parameter.toString()}`, {
            headers: { "X-Requested-With": "XMLHttpRequest" },
            signal: searchController.signal,
        });
        if (!response.ok) throw new Error("Suchanfrage fehlgeschlagen");
        const daten = await response.json();
        if (sequence !== searchSequence) return;
        searchResults.innerHTML = daten.html;
        const bezeichnung = daten.anzahl === 1 ? "Auftrag" : "Aufträge";
        if (searchStatus) searchStatus.textContent = `${daten.anzahl} ${bezeichnung} angezeigt.`;
        const url = new URL(window.location.href);
        if (suche === "") url.searchParams.delete("suche");
        else url.searchParams.set("suche", suche);
        if (kategorie === "alle") url.searchParams.delete("kategorie");
        else url.searchParams.set("kategorie", kategorie);
        window.history.replaceState(null, "", url);
    } catch (error) {
        if (error.name === "AbortError" || sequence !== searchSequence) return;
        if (searchStatus) searchStatus.textContent = "Die Suche ist fehlgeschlagen. Bitte versuchen Sie es erneut.";
    } finally {
        if (sequence === searchSequence) searchResults.setAttribute("aria-busy", "false");
    }
}

function sucheEinplanen() {
    if (!searchInput) return;
    if (searchClear) searchClear.hidden = searchInput.value.length === 0;
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(sucheAusfuehren, 275);
}
searchInput?.addEventListener("input", sucheEinplanen);
searchCategory?.addEventListener("change", () => {
    window.clearTimeout(searchTimer);
    sucheAusfuehren();
});
liveSearchForm?.addEventListener("submit", (event) => {
    event.preventDefault();
    window.clearTimeout(searchTimer);
    sucheAusfuehren();
});
searchClear?.addEventListener("click", () => {
    searchInput.value = "";
    searchInput.focus();
    sucheEinplanen();
});

const documentSelectDialog = document.querySelector("#document-select-dialog");
const documentSelectContent = documentSelectDialog?.querySelector("[data-document-select-content]");
const documentSelectTitle = documentSelectDialog?.querySelector("[data-document-select-title]");

function dokumentAuswahlAktualisieren(formular) {
    const felder = [...formular.querySelectorAll("[data-selection-device]:not(:disabled)")];
    const ausgewaehlt = felder.filter((feld) => feld.checked);
    const gesamt = ausgewaehlt.reduce((summe, feld) => summe + Number(feld.dataset.priceCent || 0), 0);
    const count = formular.querySelector("[data-selection-count]");
    const total = formular.querySelector("[data-selection-total]");
    const submit = formular.querySelector("[data-selection-submit]");
    const alle = formular.querySelector("[data-select-all]");
    const fehler = formular.querySelector("[data-selection-error]");
    if (count) count.textContent = String(ausgewaehlt.length);
    if (total) total.textContent = centFormatieren(gesamt);
    if (submit) submit.disabled = ausgewaehlt.length === 0;
    if (alle) {
        alle.checked = felder.length > 0 && ausgewaehlt.length === felder.length;
        alle.indeterminate = ausgewaehlt.length > 0 && ausgewaehlt.length < felder.length;
    }
    if (fehler && ausgewaehlt.length > 0) fehler.hidden = true;
}

function dokumentAuswahlVerdrahten(formular) {
    formular.addEventListener("change", (event) => {
        if (event.target.matches("[data-select-all]")) {
            formular.querySelectorAll("[data-selection-device]:not(:disabled)").forEach((feld) => { feld.checked = event.target.checked; });
        }
        dokumentAuswahlAktualisieren(formular);
    });
    formular.querySelector("[data-dialog-cancel]")?.addEventListener("click", () => documentSelectDialog?.close());
    formular.addEventListener("submit", (event) => {
        if (!formular.querySelector("[data-selection-device]:checked")) {
            event.preventDefault();
            const fehler = formular.querySelector("[data-selection-error]");
            if (fehler) fehler.hidden = false;
            return;
        }
        window.setTimeout(() => documentSelectDialog?.close(), 0);
    });
    dokumentAuswahlAktualisieren(formular);
}

document.addEventListener("click", async (event) => {
    const trigger = event.target.closest("[data-document-select]");
    if (!trigger || !documentSelectDialog || !documentSelectContent) return;
    event.preventDefault();
    if (documentSelectDialog.open) return;
    documentSelectContent.innerHTML = '<p class="muted">Geräte werden geladen …</p>';
    documentSelectDialog.showModal();
    try {
        const parameter = new URLSearchParams({ auftrag_id: trigger.dataset.orderId, typ: trigger.dataset.documentType });
        const antwort = await fetch(`dokument_auswahl.php?${parameter.toString()}`, { headers: { Accept: "application/json" } });
        const daten = await antwort.json();
        if (!antwort.ok || !daten.ok) throw new Error(daten.fehler || "Die Geräteauswahl konnte nicht geladen werden.");
        if (documentSelectTitle) documentSelectTitle.textContent = daten.titel;
        documentSelectContent.innerHTML = daten.html;
        const formular = documentSelectContent.querySelector("[data-document-selection-form]");
        if (formular) dokumentAuswahlVerdrahten(formular);
    } catch (fehler) {
        documentSelectDialog.close();
        meldungZeigen(fehler.message, "error");
    }
});

document.querySelectorAll("[data-print]").forEach((button) => button.addEventListener("click", () => window.print()));

document.querySelectorAll("form[data-pickup-confirm]").forEach((formular) => {
    formular.addEventListener("submit", (event) => {
        if (!window.confirm("Möchten Sie die Übergabe dieses Geräts jetzt verbindlich als abgeholt speichern?")) {
            event.preventDefault();
        }
    });
});

document.addEventListener("submit", (event) => {
    const formular = event.target;
    if (!(formular instanceof HTMLFormElement) || formular === liveSearchForm || event.defaultPrevented) return;
    if (formular.dataset.nativeSubmitting === "true") {
        event.preventDefault();
        return;
    }
    formular.dataset.nativeSubmitting = "true";
    formular.querySelectorAll('button[type="submit"]').forEach((button) => {
        button.disabled = true;
        button.setAttribute("aria-disabled", "true");
    });
});
