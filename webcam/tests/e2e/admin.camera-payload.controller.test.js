const path = require('path');
const { test, expect } = require('@playwright/test');

const REPOSITORY_ROOT = process.env.WEBCAM_REPOSITORY_ROOT || '/var/www/html/webcam';
const PAYLOAD_SCRIPT = path.join(REPOSITORY_ROOT, 'assets/js/admin-camera-payload.js');

test.describe('Ausgelagerter Admin-Kamera-Payload', () => {
  test('verwendet identische Fachbuilder für Abschnitts- und Gesamtpayloads', async ({ page }) => {
    await page.setContent(`
      <form id="camera-form">
        <input name="slug" value="bielefeld">
        <input name="name" value=" Bielefeld ">
        <input name="title" value=" Stadtblick ">
        <input name="short_title" value="">
        <input name="tags" value="stadt, aussicht, stadt">
        <input name="native_display" type="checkbox" checked>
        <input name="night_denoise" type="checkbox">
        <textarea name="description"> Beschreibung </textarea>
        <textarea name="info_html"> veraltet </textarea>

        <input name="latitude" value="52,03">
        <input name="longitude" value="8.53">
        <input name="elevation" value="120">
        <input name="orientation_deg" value="180">
        <input name="field_of_view_deg" value="70">
        <input name="map_height_px" value="520">
        <input name="map_center_offset" value="1.5">
        <input name="map_center_offset_x" value="-2">
        <input name="orientation_visualization_km" value="7.5">

        <select name="mode"><option value="active-capture" selected>active</option></select>
        <select name="transport"><option value="rtsp" selected>rtsp</option></select>
        <input name="endpoint" value=" rtsp://camera.invalid/live ">
        <input name="interval_seconds" value="600">
        <input name="timeout_seconds" value="15">
        <input name="expected_response" value="200">
        <input name="extra_times" value="06:10, 18:20">
        <input name="upload_key" value=" secret ">
        <select name="helper_upload_mode"><option value="chunked" selected>Chunked</option></select>
        <input name="active_upload_fallback_failures" value="3">
        <select name="auto_iso_mode"><option value="observe" selected>Beobachten</option></select>
        <input name="auto_iso_min" value="100">
        <input name="auto_iso_max" value="800">
        <input name="night_ev_max" value="2">
        <input name="stream_fps" value="3.7">
        <input name="stream_quality" value="99">
        <input name="stream_max_clients" value="20" disabled>

        <input name="base_path" value="">
        <input name="current_dir" value=" current ">
        <input name="archive_dir" value=" archive ">
        <input name="best_of_dir" value=" bestof ">
        <input name="resolutions" value="1600, 800, 1600, ungueltig">
        <input name="overlay_light_url" value="overlay-light.png">
        <input name="overlay_dark_url" value="overlay-dark.png">
        <textarea name="weather_commands">echo first\n\necho second</textarea>
        <input name="watchdog_enabled" type="checkbox" checked>
        <input name="watchdog_failures" value="4">
        <input name="cooldown_successes" value="2">
        <input name="cooldown_seconds" value="900">
        <textarea name="reset_sequence">[{"type":"http-get","url":"http://reset.invalid"}]</textarea>
      </form>`);
    await page.addScriptTag({ path: PAYLOAD_SCRIPT });

    const result = await page.evaluate(() => {
      const form = document.getElementById('camera-form');
      const calls = { sync: 0, overlays: [], labels: 0 };
      const toNumber = (value, fallback = 0) => {
        const raw = String(value ?? '').trim().replace(/,/g, '.');
        if (!raw) return fallback;
        const parsed = Number(raw);
        return Number.isFinite(parsed) ? parsed : fallback;
      };
      const cleanString = (value) => String(value ?? '').trim();
      const splitCommaSeparated = (value) => String(value ?? '')
        .split(',')
        .map((entry) => entry.trim())
        .filter(Boolean);
      const buildCameraTagsFromForm = (formData) => {
        const tags = Array.from(new Set(splitCommaSeparated(formData.get('tags'))));
        if (formData.has('native_display')) tags.push('__native_display');
        if (formData.has('night_denoise')) tags.push('__night_denoise');
        return tags;
      };
      const controller = window.WebcamAdminCameraPayload.create({
        toNumber,
        cleanString,
        splitCommaSeparated,
        buildCameraTagsFromForm,
        overlayEditor: {
          parseFormData: (formData, variant) => {
            calls.overlays.push(variant);
            return { variant, url: formData.get(`overlay_${variant}_url`) };
          },
        },
        labelEditor: {
          collect: () => {
            calls.labels += 1;
            return [{ text: 'Infopunkt', x: 10, y: 20 }];
          },
        },
        syncRichText: () => {
          calls.sync += 1;
          form.elements.info_html.value = ' <p>Aktueller Editorinhalt</p> ';
        },
      });
      const existingStream = {
        mode: 'rtsp',
        playlist_url: 'stream/bielefeld',
        snapshot_url: 'snapshot/bielefeld',
        fps: 2,
        quality: 5,
        max_clients: 4,
      };

      const complete = controller.buildComplete(form, { existingStream });
      const sections = Object.fromEntries(controller.sectionIds.map((sectionId) => [
        sectionId,
        controller.buildSection(form, sectionId, { existingStream }),
      ]));

      const errors = {};
      try {
        controller.buildSection(form, 'unbekannt');
      } catch (error) {
        errors.unknown = error.message;
      }
      form.elements.reset_sequence.value = '{"not":"an array"}';
      try {
        controller.buildSection(form, 'watchdog');
      } catch (error) {
        errors.reset = error.message;
      }
      form.elements.reset_sequence.value = JSON.stringify(Array.from({ length: 21 }, () => ({ type: 'delay', seconds: 1 })));
      try {
        controller.buildSection(form, 'watchdog');
      } catch (error) {
        errors.tooMany = error.message;
      }
      form.elements.slug.value = '';
      try {
        controller.buildComplete(form);
      } catch (error) {
        errors.slug = error.message;
      }

      return { complete, sections, calls, errors };
    });

    expect(result.complete).toEqual({
      id: 'bielefeld',
      slug: 'bielefeld',
      name: 'Bielefeld',
      title: 'Stadtblick',
      short_title: 'Bielefeld',
      description: 'Beschreibung',
      info_html: '<p>Aktueller Editorinhalt</p>',
      tags: ['stadt', 'aussicht', '__native_display'],
      mode: 'active-capture',
      location: {
        latitude: 52.03,
        longitude: 8.53,
        elevation: 120,
        orientation_deg: 180,
        field_of_view_deg: 70,
        map_height_px: 520,
        map_center_offset: 1.5,
        map_center_offset_x: -2,
        orientation_visualization_km: 7.5,
      },
      capture: {
        interval_seconds: 600,
        transport: 'rtsp',
        endpoint: 'rtsp://camera.invalid/live',
        timeout_seconds: 15,
        expected_response: 200,
        extra_times: ['06:10', '18:20'],
        upload_key: 'secret',
        helper_upload_mode: 'chunked',
        auto_iso: { mode: 'observe', min_iso: 100, max_iso: 800, night_ev_max: 2 },
        active_upload_fallback_failures: 3,
      },
      stream: {
        mode: 'rtsp',
        playlist_url: 'stream/bielefeld',
        snapshot_url: 'snapshot/bielefeld',
        fps: 4,
        quality: null,
        max_clients: 4,
      },
      storage: {
        base_path: 'storage/bielefeld',
        current_dir: 'current',
        archive_dir: 'archive',
        best_of_dir: 'bestof',
      },
      status: {
        watchdog: {
          enabled: true,
          max_consecutive_failures: 4,
          reset_sequence: [{ type: 'http-get', url: 'http://reset.invalid' }],
          cooldown: { min_successes_after_reset: 2, min_seconds_after_reset: 900 },
        },
      },
      weather_commands: ['echo first', 'echo second'],
      overlay_logo: { variant: 'light', url: 'overlay-light.png' },
      overlay_logo_dark: { variant: 'dark', url: 'overlay-dark.png' },
      labels: [{ text: 'Infopunkt', x: 10, y: 20 }],
      resolutions: [800, 1600],
    });

    // Die Teilpayloads müssen exakt dieselben Objekte liefern, die im
    // Gesamtpayload landen. Nur die Kurzbezeichnungs-Automatik gilt bewusst
    // ausschließlich für die komplette Neuanlage/Aktualisierung.
    expect(result.sections.general).toEqual({
      name: 'Bielefeld',
      title: 'Stadtblick',
      short_title: '',
      tags: result.complete.tags,
    });
    expect(result.sections.info).toEqual({
      description: result.complete.description,
      info_html: result.complete.info_html,
    });
    expect(result.sections.liveview).toEqual({ tags: result.complete.tags });
    expect(result.sections.map).toEqual({ location: result.complete.location });
    expect(result.sections.capture).toEqual({
      mode: result.complete.mode,
      capture: result.complete.capture,
      stream: result.complete.stream,
    });
    expect(result.sections.storage).toEqual({ storage: result.complete.storage });
    expect(result.sections.overlay).toEqual({
      overlay_logo: result.complete.overlay_logo,
      overlay_logo_dark: result.complete.overlay_logo_dark,
    });
    expect(result.sections.labels).toEqual({ labels: result.complete.labels });
    expect(result.sections.weather).toEqual({ weather_commands: result.complete.weather_commands });
    expect(result.sections.watchdog).toEqual({ status: result.complete.status });

    expect(result.calls.sync).toBe(14);
    expect(result.calls.overlays).toEqual(['light', 'dark', 'light', 'dark']);
    expect(result.calls.labels).toBe(2);
    expect(result.errors).toEqual({
      unknown: 'Unbekannte Sektion: unbekannt',
      reset: 'Reset-Sequenz ungültig: Reset-Sequenz muss ein Array sein',
      tooMany: 'Reset-Sequenz ungültig: Maximal 20 Reset-Schritte erlaubt',
      slug: 'Slug darf nicht leer sein',
    });
  });
});
