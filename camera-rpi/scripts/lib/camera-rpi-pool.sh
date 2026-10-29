#!/usr/bin/env bash
# Aufnahme-Pool: einmal fotografieren, dasselbe Bild an mehrere Server senden.
# Eine "Generation" umfasst das JPEG und eine Liste noch offener Empfaenger.
# Ihre Nummer bezeichnet einen Auftrag, nicht zwingend einen 10-Minuten-Slot.
# Ein Hardlink ist ein weiterer Dateiname fuer denselben Inhalt. So verbraucht
# das Foto nur einmal RAM, obwohl jedes Ziel seine eigene Versandliste hat.
# RAM-Inhalte gehen bei einem Pi-Neustart verloren.
#
# Ablauf: Empfaenger festlegen -> aufnehmen -> Bild verteilen -> je Ziel senden
# -> bestaetigte Lieferung aus dessen Liste entfernen. Ein langsames Ziel darf
# die bereits bestaetigten Lieferungen anderer Ziele nicht rueckgaengig machen.
#
# Der Aufrufer muss lokale Pool-Aenderungen mit operation.lock sperren, damit
# nicht zwei Worker gleichzeitig dieselben Dateien aendern. Der Netzwerkversand
# und das Warten auf ACK (Serverbestaetigung) erfolgen ausserhalb dieser Sperre.
# Ausnahme: cr_pool_latest liest nur den in einem Schritt ersetzten Zaehler.
# Die gemeinsam geladenen Helfer liefern gepruefte Einstellungen und Funktionen.
# Konfiguration und Serverantworten sind Daten, niemals auszufuehrender Shellcode.

# Ein Ziel liefert die Kameraeinstellungen, aber ist kein Aufnahme-Leader:
# Auch ein anderer Client darf zuerst ausloesen. Seine URL/Keys bleiben lokal.
cr_pool_load() {
  local policy="${CAMERA_RPI_POOL_CONFIG:-/etc/camera-rpi/capture-pool.conf}"
  local line key value profile source_path
  local -A seen=()
  POOL_ENABLED=0 POOL_SOURCE_SERVER='' POOL_LEGACY_TIMEZONE=''
  [[ -e "$policy" || -L "$policy" ]] || { cr_source_load; return $?; }
  [[ ! -L "$policy" ]] && cr_private_file "$policy" Poolkonfiguration || return 1
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="$(cr_trim "${line%$'\r'}")"
    [[ -z "$line" || "$line" == \#* ]] && continue
    [[ "$line" == *=* ]] || return 1
    key="${line%%=*}" value="${line#*=}"
    [[ "$key" =~ ^[a-z_]+$ ]] || return 1
    [[ -z "${seen[$key]+x}" ]] || return 1
    seen[$key]=1
    case "$key" in
      source_server) POOL_SOURCE_SERVER="$value" ;;
      legacy_timezone)
        # Kalenderzeiten fuer den alten Server gehoeren zu dessen Zeitzone,
        # nicht zwingend zur lokalen Pi-Zeitzone. Nur installierte IANA-Zonen.
        [[ "$value" =~ ^[A-Za-z0-9_+-]+(/[A-Za-z0-9_+-]+)*$ \
          && -f "/usr/share/zoneinfo/$value" ]] || {
          cr_fail 'Ungueltige Legacy-Zeitzone im Pool'; return 1;
        }
        POOL_LEGACY_TIMEZONE="$value" ;;
      *) cr_fail "Unbekannte Pooloption: $key"; return 1 ;;
    esac
  done < "$policy"
  [[ "$POOL_SOURCE_SERVER" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]] || return 1
  source_path="$(dirname -- "$policy")/servers/$POOL_SOURCE_SERVER.conf"
  [[ ! -L "$source_path" ]] || return 1
  # In einer getrennten Shell lesen, damit Zugangsdaten und Serveradresse
  # des Profilgebers nicht die des aktuellen Workers ersetzen. Nur die unten
  # ausdruecklich ausgegebenen, geprueften Kameraeinstellungen uebernehmen.
  profile="$(
    cr_load_server_config "$source_path" || exit 1
    [[ "$SERVER_ID" == "$POOL_SOURCE_SERVER" ]] || exit 1
    printf '%s\n' "$CAPTURE_BACKEND" "$GPHOTO_CAPTURE_MODE" "$GPHOTO_TIMEOUT_SECONDS" \
      "$VIDEO_DEVICE" "$VIDEO_WIDTH" "$VIDEO_HEIGHT" "$VIDEO_WARMUP_FRAMES" \
      "$MIN_IMAGE_BYTES" "$MAX_IMAGE_BYTES" "$SPOOL_ROOT"
  )" || { cr_fail 'Gemeinsames Kameraprofil ist ungueltig'; return 1; }
  local -a fields=()
  mapfile -t fields <<< "$profile"
  (( ${#fields[@]} == 10 )) && [[ "${fields[9]}" == "$SPOOL_ROOT" ]] || {
    cr_fail 'Alle Pool-Clients muessen denselben Spool verwenden'; return 1;
  }
  CAPTURE_BACKEND="${fields[0]}" GPHOTO_CAPTURE_MODE="${fields[1]}" GPHOTO_TIMEOUT_SECONDS="${fields[2]}"
  VIDEO_DEVICE="${fields[3]}" VIDEO_WIDTH="${fields[4]}" VIDEO_HEIGHT="${fields[5]}" VIDEO_WARMUP_FRAMES="${fields[6]}"
  MIN_IMAGE_BYTES="${fields[7]}" MAX_IMAGE_BYTES="${fields[8]}"
  POOL_ROOT="$SPOOL_ROOT/.capture-pool"
  POOL_CONFIG_DIR="$(dirname -- "$policy")/servers"
  POOL_ENABLED=1
  cr_source_load
}

# Eine mitgelieferte Server-Kalenderzeit unveraendert verwenden. Nur wenn sie
# fehlt, die Epoch-Zeit in der konfigurierten Legacy-Zeitzone formatieren.
# Ohne diese optionale Einstellung bleibt das bisherige Verhalten erhalten.
cr_pool_legacy_stamp() {
  local serial="$1" stamp="${2:-}"
  if [[ -n "$stamp" ]]; then
    legacy_capture_stamp_is_valid "$stamp" || return 1
  else
    [[ "$serial" =~ ^[0-9]{10}$ ]] || return 1
    if [[ -n "${POOL_LEGACY_TIMEZONE:-}" ]]; then
      stamp="$(TZ="$POOL_LEGACY_TIMEZONE" date -d "@$serial" +%Y%m%d_%H%M)" || return 1
    else
      stamp="$(date -d "@$serial" +%Y%m%d_%H%M)" || return 1
    fi
    stamp="$(legacy_capture_slot_stamp "$stamp")" || return 1
  fi
  printf '%s' "$stamp"
}

# Vor der Serverabfrage den letzten gemeinsam bearbeiteten Auftrag merken.
# Ist die Nummer danach hoeher, hat ein anderer Worker den Pool schon bedient.
# Dann ist fuer diese gleichzeitig laufenden Anfragen kein weiteres Foto noetig.
cr_pool_latest() {
  local latest=0
  if [[ -e "$POOL_ROOT/latest" ]]; then
    [[ ! -L "$POOL_ROOT/latest" ]] && read -r latest < "$POOL_ROOT/latest" || return 1
    [[ "$latest" =~ ^[0-9]{10}$ ]] || return 1
  fi
  printf '%s' "$latest"
}

# Der Pool muss auf tmpfs (RAM-Dateisystem) liegen. Bei falscher Einbindung
# abbrechen, statt unbemerkt die SD-Karte mit Bildern zu beschreiben.
# Das separate Warteschlangenlimit wird in cr_pool_trim behandelt.
cr_pool_storage() {
  [[ ! -L "$POOL_ROOT" ]] || return 1
  mkdir -p -- "$POOL_ROOT" && chmod 0700 "$POOL_ROOT" || return 1
  [[ "$("$FINDMNT_BIN" -n -o FSTYPE -T "$POOL_ROOT")" == tmpfs ]] || {
    cr_fail 'Gemeinsamer Bilderpool muss im tmpfs liegen'; return 1;
  }
}

# Empfaenger VOR der Aufnahme festhalten. Deaktivierte Ziele erhalten keine
# neuen Bilder. Bereits vorgemerkte Lieferungen bleiben bestehen, auch wenn
# ein Ziel danach deaktiviert oder aus der Konfiguration entfernt wird.
cr_pool_recipients() {
  local target info id root enabled found=0
  for target in "$POOL_CONFIG_DIR"/*.conf; do
    [[ -f "$target" && ! -L "$target" ]] || return 1
    info="$(cr_load_server_config "$target" || exit 1; printf '%s\t%s\t%s' "$SERVER_ID" "$SPOOL_ROOT" "$ENABLED")" || return 1
    IFS=$'\t' read -r id root enabled <<< "$info"
    [[ "$id.conf" == "${target##*/}" && "$root" == "$SPOOL_ROOT" ]] || return 1
    [[ "$enabled" == 1 ]] || continue
    printf '%s\n' "$id"
    [[ "$id" != "$SERVER_ID" ]] || found=1
  done
  (( found ))
}

# Nach einem Prozessabbruch die Verteilung desselben Bildes fortsetzen.
# Eine Datei unter pending bedeutet: Dieses Ziel ist noch nicht erledigt.
# Sie wird nach ACK oder bewusstem Verwerfen (Limit/defektes Bild) entfernt.
# Begleitdaten zuerst bereitstellen, dann den Bildnamen per Hardlink freigeben.
# Ein vorhandenes Zielbild muss auf denselben Dateiinhalt zeigen; sonst abbrechen.
cr_pool_distribute() {
  local generation="$1" serial="${1##*/}" pending id image
  [[ "$serial" =~ ^[0-9]{10}$ && ! -L "$generation" ]] || return 1
  valid_jpeg "$generation/image.jpg" || return 1
  for pending in "$generation/pending/"*; do
    [[ -e "$pending" ]] || continue
    id="${pending##*/}"
    [[ "$id" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ && ! -L "$pending" ]] || return 1
    image="$SPOOL_ROOT/$id/images/$serial.jpg"
    [[ ! -L "$SPOOL_ROOT/$id" && ! -L "$SPOOL_ROOT/$id/images" && ! -L "$image" ]] || return 1
    mkdir -p -- "$SPOOL_ROOT/$id/images" "$SPOOL_ROOT/$id/requests" || return 1
    if [[ -f "$generation/captured" ]]; then
      cp -- "$generation/captured" "$image.captured.tmp.$$" \
        && mv -f -- "$image.captured.tmp.$$" "$image.captured" || return 1
    fi
    if [[ -e "$image" ]]; then
      [[ "$image" -ef "$generation/image.jpg" ]] || { cr_fail "Zielbild-Konflikt: $id/$serial"; return 1; }
      continue
    fi
    cp -- "$generation/stamp" "$image.legacy-stamp" || return 1
    if [[ -f "$generation/weather.json" ]]; then
      cp -- "$generation/weather.json" "$image.weather.json" || return 1
    fi
    ln -- "$generation/image.jpg" "$image" || return 1
  done
}

# Vor Versand oder neuer Aufnahme unter der Kamerasperre liegengebliebene
# Poolarbeiten vervollstaendigen. Wiederholtes Ausfuehren ist zulaessig:
# fehlende Verweise ergaenzen und den letzten Auftrag nur vorwaerts setzen.
# So wird nach einem Worker-Abbruch ein vorhandenes Bild weiterverwendet.
cr_pool_recover() {
  local generation latest serial pending pending_found
  cr_pool_storage || return 1
  latest="$(cr_pool_latest)" || return 1
  for generation in "$POOL_ROOT"/[0-9]*; do
    [[ -d "$generation" ]] || continue
    serial="${generation##*/}"
    [[ "$serial" =~ ^[0-9]{10}$ ]] || return 1
    if (( 10#$serial > latest )); then latest="$serial"; fi
    pending_found=0
    [[ -d "$generation/pending" && ! -L "$generation/pending" ]] || return 1
    for pending in "$generation/pending/"*; do
      [[ ! -e "$pending" ]] || pending_found=1
    done
    if (( pending_found )); then
      cr_pool_distribute "$generation" || return 1
    else
      # Alle Empfaenger sind erledigt. Auch dann aufraeumen, wenn der vorige
      # Prozess bereits das JPEG entfernt hat und erst danach abgebrochen ist.
      cr_pool_acknowledge "$serial" || return 1
    fi
  done
  if [[ "$latest" != 0 ]]; then
    printf '%s\n' "$latest" > "$POOL_ROOT/latest.tmp" && mv -f -- "$POOL_ROOT/latest.tmp" "$POOL_ROOT/latest" || return 1
  fi
  cr_pool_trim
}

# Betreiberregel: je Ziel hoechstens zehn wartende Bilder behalten, aelteste
# zuerst verwerfen. Das ist KEINE erfolgreiche Lieferung und wird protokolliert.
# Wird das aelteste Bild gerade hochgeladen, vorerst mehr als zehn erlauben;
# beim naechsten Poolaufruf erneut pruefen. Andere Zielwarteschlangen behalten
# ihre Verweise auf dieses Bild.
cr_pool_trim() {
  local directory id image serial trim_fd count
  local -a images=()
  for directory in "$SPOOL_ROOT"/*/images; do
    [[ -d "$directory" && ! -L "$directory" ]] || continue
    id="${directory%/images}"; id="${id##*/}"
    [[ "$id" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]] || return 1
    images=()
    for image in "$directory"/*.jpg; do [[ ! -f "$image" ]] || images+=("$image"); done
    count="${#images[@]}"
    for image in "${images[@]}"; do
      (( count > 10 )) || break
      serial="${image##*/}"; serial="${serial%.jpg}"
      [[ "$serial" =~ ^[0-9]{10}$ && ! -L "$image" ]] || return 1
      exec {trim_fd}<"$image" || return 1
      if ! "$FLOCK_BIN" -x -n "$trim_fd"; then exec {trim_fd}<&-; break; fi
      if ! { rm -f -- "$image" "$image.weather.json" "$image.legacy-stamp" "$image.captured" \
        && cr_pool_acknowledge "$serial" "$id"; }; then
        exec {trim_fd}<&-; return 1
      fi
      exec {trim_fd}<&-
      cr_log WARNING "Poolbild $serial fuer $id verworfen: Warteschlange ueber 10 Bilder"
      ((count-=1))
    done
  done
}

# Alle Worker teilen die Wiederholungspause nach einem Aufnahmefehler:
# 30, 60, 120, 240, dann hoechstens 300 Sekunden. Zustand unter operation.lock
# lesen/schreiben. Laufzeit seit Pi-Start statt Uhrzeit verwenden, damit eine
# Uhrkorrektur die Pause nicht verlaengert oder ueberspringt. Hier nicht schlafen:
# In der Pause brauchen Sensoren und manuelle Kameraaktionen die freie Sperre.
cr_pool_backoff_read() {
  POOL_RETRY_UNTIL=0 POOL_RETRY_DELAY=0
  [[ ! -L "$POOL_ROOT/capture-backoff" ]] || return 1
  if [[ -e "$POOL_ROOT/capture-backoff" ]]; then
    local extra
    read -r POOL_RETRY_UNTIL POOL_RETRY_DELAY extra < "$POOL_ROOT/capture-backoff" || return 1
    [[ "$POOL_RETRY_UNTIL" =~ ^[0-9]{1,10}$ && "$POOL_RETRY_DELAY" =~ ^[0-9]{1,3}$ && -z "$extra" ]] || return 1
    POOL_RETRY_UNTIL=$((10#$POOL_RETRY_UNTIL)) POOL_RETRY_DELAY=$((10#$POOL_RETRY_DELAY))
    (( POOL_RETRY_DELAY >= 30 && POOL_RETRY_DELAY <= 300 )) || return 1
  fi
}

cr_pool_backoff_ready() {
  local uptime rest now
  cr_pool_backoff_read || return 1
  read -r uptime rest < /proc/uptime || return 1
  now=${uptime%%.*}
  if (( now < POOL_RETRY_UNTIL )); then
    write_status waiting "Kamerafehler: gemeinsame Wiederholungspause, noch $((POOL_RETRY_UNTIL-now)) Sekunden"
    return 1
  fi
}

cr_pool_backoff_failed() {
  local uptime rest delay
  cr_pool_backoff_read || return 1
  read -r uptime rest < /proc/uptime || return 1
  delay=$(( POOL_RETRY_DELAY == 0 ? 30 : POOL_RETRY_DELAY * 2 ))
  (( delay <= 300 )) || delay=300
  printf '%s %s\n' "$(( ${uptime%%.*} + delay ))" "$delay" > "$POOL_ROOT/capture-backoff.tmp" \
    && mv -f -- "$POOL_ROOT/capture-backoff.tmp" "$POOL_ROOT/capture-backoff" || return 1
  cr_log WARNING "Kameraaufnahme fehlgeschlagen; gemeinsame Wiederholungspause $delay Sekunden"
}

# Ein neuer Auftrag kann eine gemeinsame Aufnahme ausloesen. Hat ein anderer
# Worker seit Beginn unserer Serverabfrage schon einen neueren Poolauftrag
# bearbeitet, benutzen wir dessen Ergebnis, statt nochmals zu fotografieren.
cr_pool_capture_locked() {
  local observed="$1" serial="$2" image="$3" output="$4" latest stage generation stamp recipients id backend_exit
  cr_pool_recover || return 1
  latest="$(cr_pool_latest)" || return 1
  # Der alte Scheduler kann seinen datierten Auftrag erst nach dem Upload
  # eines anderen Ziels liefern. Seine Sekunden-ID ist dann neu, der Bildslot
  # aber bereits erfuellt. Nur explizit datierte Legacy-Auftraege vergleichen;
  # undatierte/manuelle Auftraege behalten ihre eigene Identitaet. Die kurze
  # Epoch-Differenz verhindert Verwechslungen bei der doppelten DST-Stunde.
  if [[ -n "${REQUEST_LEGACY_STAMP:-}" ]] && (( latest > 0 && 10#$serial > latest && 10#$serial-latest < 600 )); then
    stamp="$(cr_pool_legacy_stamp "$latest")" || return 1
    if [[ "$stamp" == "$REQUEST_LEGACY_STAMP" ]]; then
      cr_log INFO "Legacy-Slot $stamp durch gemeinsame Generation $latest abgedeckt; keine zweite Aufnahme"
      return 0
    fi
  fi
  if (( latest > observed || 10#$serial <= latest )); then
    cr_log INFO "Capture-Auftrag $serial durch gemeinsame Generation $latest abgedeckt"
    return 0
  fi
  # Dieses Ziel muss seine wartenden Bilder zuerst versenden.
  if [[ -n "$(oldest_file "$SERVER_SPOOL_DIR/images" .jpg)" ]]; then
    cr_log INFO 'Wartende Poolbilder werden zuerst uebertragen'
    return 0
  fi
  recipients="$(cr_pool_recipients)" || { cr_fail 'Pool-Ziele konnten nicht vollstaendig bestimmt werden'; return 1; }
  stage="$POOL_ROOT/.$serial.build" generation="$POOL_ROOT/$serial"
  [[ ! -L "$stage" ]] && mkdir -p -- "$stage/pending" || return 1
  # Der alte Server benoetigt einen Kalenderstempel. Er gehoert zum Auftrag
  # dieses Bildes und darf bei spaeteren Uploadversuchen nicht weiterwandern.
  stamp="$(cr_pool_legacy_stamp "$serial" "${REQUEST_LEGACY_STAMP:-}")" || return 1
  printf '%s\n' "$stamp" > "$stage/stamp" || return 1
  for id in $recipients; do : > "$stage/pending/$id" || return 1; done
  if [[ ! -f "$stage/image.jpg" ]]; then
    cr_pool_backoff_ready || return 1
    local CR_CAPTURE_ATTEMPT_MARKER="$output.attempted"
    rm -f -- "$CR_CAPTURE_ATTEMPT_MARKER" || return 1
    rm -f -- "$stage/image.part" || return 1
    write_status capturing "Erste Anforderung: gemeinsame Aufnahme $serial"
    cr_log INFO "Gemeinsame Kameraaufnahme $serial beginnt (Profil $POOL_SOURCE_SERVER)"
    if cr_capture_to_file "$stage/image.part" > "$output" 2>&1; then
      { valid_jpeg "$stage/image.part" && decoded_jpeg "$stage/image.part"; } || {
        cr_capture_recovery failed >> "$output" 2>&1 || true
        save_capture_failure "$output" "$serial" 1 || true
        cr_pool_backoff_failed || true
        cr_fail 'Poolaufnahme ist kein gueltiges JPEG'; return 1;
      }
    else
      backend_exit=$?
      if (( backend_exit == 3 )); then
        cr_capture_recovery ok || true
        privacy_capture_skipped "$serial" "$output"
        # Die Datenschutzpruefung hat die gemeinsame Aufnahme verworfen.
        # Den Auftrag fuer alle gleichzeitig anfragenden Ziele als bearbeitet
        # merken, damit nicht jedes Ziel erneut dasselbe gesperrte Motiv aufnimmt.
        printf '%s\n' "$serial" > "$POOL_ROOT/latest.tmp" \
          && mv -f -- "$POOL_ROOT/latest.tmp" "$POOL_ROOT/latest" || return 1
        rm -f -- "$stage/image.part" "$stage/stamp" "$output" || return 1
        for id in $recipients; do rm -f -- "$stage/pending/$id" || return 1; done
        rmdir -- "$stage/pending" "$stage" || return 1
        return 0
      fi
      cr_capture_recovery failed >> "$output" 2>&1 || true
      save_capture_failure "$output" "$serial" "$backend_exit" || true
      cr_pool_backoff_failed || true
      rm -f -- "$stage/image.part"
      return 1
    fi
    cr_capture_recovery ok || true
    rm -f -- "$POOL_ROOT/capture-backoff" || return 1
    # Beobachtete Pi-Zeit nach erfolgreicher Aufnahme und Bildpruefung merken,
    # nicht den alten Sollslot und nicht die spaetere Uploadzeit. Das ist kein
    # exakt gemessener Zeitpunkt, an dem der Kameraverschluss ausgeloest hat.
    printf '%s\n' "$(date +%s)" > "$stage/captured" || return 1
    if [[ -r "$WEATHER_HELPER" ]]; then
      /usr/bin/python3 "$WEATHER_HELPER" snapshot "$STATUS_PUBLISH_ROOT/current.status" "$stage/weather.json" "$(<"$stage/captured")" \
        || cr_log WARNING 'Lokale Wetterdaten fuer Poolbild nicht uebernommen'
    fi
    mv -- "$stage/image.part" "$stage/image.jpg" || return 1
  fi
  mv -- "$stage" "$generation" || return 1
  cr_pool_recover || return 1
  publish_latest_capture "$generation/image.jpg" "$serial" "$(<"$generation/captured")" \
    || cr_log WARNING 'Lokale Pool-Vorschau konnte nicht aktualisiert werden'
  cr_log INFO "Poolbild $serial fuer alle aktiven Ziele vorgemerkt"
  rm -f -- "$output"
}

# Ein Ziel aus der offenen Versandliste entfernen: nach Serverbestaetigung,
# Warteschlangenlimit oder Quarantaene eines nachweislich defekten Bildes.
# Trotz des Namens acknowledge beweist dieser Aufruf allein keinen Upload!
# Der Aufrufer haelt operation.lock und hat seinen Bildverweis schon entfernt.
# Erst wenn kein Ziel mehr offen ist, die gemeinsame Bilddatei freigeben.
cr_pool_acknowledge() {
  local serial="$1" generation="$POOL_ROOT/$1" id="${2:-$SERVER_ID}" pending
  [[ -d "$generation" ]] || return 0
  [[ ! -L "$generation" && "$serial" =~ ^[0-9]{10}$ ]] || return 1
  rm -f -- "$generation/pending/$id" || return 1
  for pending in "$generation/pending/"*; do
    [[ ! -e "$pending" ]] || return 0
  done
  rm -f -- "$generation/image.jpg" "$generation/weather.json" "$generation/stamp" "$generation/captured" || return 1
  rmdir -- "$generation/pending" "$generation" || return 1
  cr_log INFO "Poolbild $serial freigegeben: keine offenen Ziele (Upload, Warteschlangenlimit oder Quarantaene)"
}
