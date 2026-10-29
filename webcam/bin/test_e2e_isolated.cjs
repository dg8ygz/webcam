#!/usr/bin/env node
'use strict';

// Copy only tracked runtime code, never live config, images, mounts or secrets.
// The private copy is also the HTTP document root: no live symlinks/fallbacks.
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const net = require('node:net');
const crypto = require('node:crypto');
const { spawn, spawnSync } = require('node:child_process');
const repo = path.resolve(__dirname, '..');
const root = fs.mkdtempSync(path.join(os.tmpdir(), 'webcam-e2e-'));
const nonce = crypto.randomBytes(16).toString('hex');
const children = new Set();
let interrupted = false;
let logFd;
const artifacts = fs.mkdtempSync(path.join(os.tmpdir(), 'webcam-e2e-report-'));
console.log(`E2E artifacts: ${artifacts}`);
fs.writeFileSync(path.join(root, '.isolated-e2e'), nonce, { mode: 0o600 });

function stopChildren() {
  // Stop only process groups launched by this fixture, never a live PHP server.
  for (const child of children) {
    try { process.kill(-child.pid, 'SIGTERM'); } catch {}
  }
}
for (const signal of ['SIGINT', 'SIGTERM']) {
  process.on(signal, () => { interrupted = true; stopChildren(); process.exitCode = 130; });
}
// Remove inherited live DB, URL, token and PHP overrides before assigning the
// private fixture paths. The copied application must have no live fallback.
const env = Object.fromEntries(Object.entries(process.env).filter(([key]) =>
  !/^(WEBCAM_|WC_|BASE_URL$|CAMERA_PATH$|ADMIN_TOKEN$|PHP_|PHPRC$)/.test(key)));
Object.assign(env, {
  WEBCAM_ENV: 'test', WEBCAM_CONFIG_DB: path.join(root, 'fixture.sqlite'),
  WEBCAM_CAMERA_DB_PATH: path.join(root, 'camera-db'), WEBCAM_CAMERA_DB_READONLY: '0',
  WEBCAM_ALLOW_SAMPLE_CONFIG: '0', WEBCAM_ALLOW_PROD_WRITE: '0',
  WEBCAM_PUBLIC_RATE_DB: path.join(root, 'rate.sqlite'),
  WEBCAM_MAINTENANCE_TICK_DISABLE: '1', WEBCAM_ADMIN_API_TOKEN: 'isolated-e2e-token',
  ADMIN_TOKEN: 'isolated-e2e-token', WEBCAM_REPOSITORY_ROOT: root,
  WEBCAM_E2E_ANCHOR: String(Math.floor(Date.now() / 1000)),
  WEBCAM_E2E_REPORT: artifacts, TMPDIR: root, TMP: root, TEMP: root,
});

function run(binary, args, options = {}) {
  // Track detached children for signal cleanup and preserve their real exit code.
  if (interrupted) throw new Error('Test run interrupted');
  const child = spawn(binary, args, { cwd: root, env, detached: true, stdio: 'inherit', ...options });
  children.add(child);
  return new Promise((resolve, reject) => {
    child.once('error', (error) => { children.delete(child); reject(error); });
    child.once('exit', (code) => { children.delete(child); resolve(code ?? 1); });
  });
}

(async () => {
  try {
    const files = spawnSync('git', ['ls-files', '-z'], { cwd: repo, encoding: 'utf8', timeout: 15000 });
    if (files.status !== 0) throw new Error('Cannot enumerate tracked runtime files');
    const allowed = /^(?:[^/]+\.php|VERSION|ITERATION|(?:src|config|templates|assets|admin|api|status|include)\/.*|database\/schema\.(?:config|camera)\.sql|tests\/e2e\/.*\.js)$/;
    const sources = files.stdout.split('\0').filter((file) => allowed.test(file));
    sources.push('tests/isolated/seed.php', 'tests/isolated/core.test.js', 'tests/isolated/playwright.config.cjs');
    // Explicit fixture regressions must also run before their first commit.
    sources.push('tests/e2e/camera.status.controller.test.js');
    sources.push('src/Service/Storage/ImageProcessingConfig.php', 'src/Service/Storage/ImageProcessor.php',
      'src/Service/Storage/ImageProcessingPreview.php', 'src/Service/Storage/EdgeDetailFilter.php',
      'src/Service/Storage/StrombergPrivacyCrop.php', 'src/Service/Storage/CaptureFilename.php',
      'assets/js/admin-image-processing.js', 'tests/e2e/admin.image-processing.controller.test.js');
    for (const file of sources) {
      const source = path.join(repo, file);
      if (!fs.lstatSync(source).isFile()) throw new Error(`Non-regular fixture source: ${file}`);
      const target = path.join(root, file);
      fs.mkdirSync(path.dirname(target), { recursive: true });
      fs.copyFileSync(source, target);
    }
    // Preserve relative asset paths in existing standalone controller tests.
    const nodeModules = process.env.PLAYWRIGHT_NODE_MODULES_PATH || path.join(repo, 'node_modules');
    const runtimeRequire = require('node:module').createRequire(path.join(nodeModules, 'playwright', 'package.json'));
    env.NODE_PATH = nodeModules;
    const cli = path.join(path.dirname(runtimeRequire.resolve('playwright/package.json')), 'cli.js');
    // Block filesystem escape and external job launches in both seed and HTTP PHP.
    const phpArgs = ['-d', `open_basedir=${root}`, '-d', `session.save_path=${root}`,
      '-d', 'allow_url_fopen=0', '-d', 'disable_functions=exec,shell_exec,system,passthru,proc_open,popen'];
    if (await run('php', [...phpArgs, 'tests/isolated/seed.php']) !== 0) throw new Error('Fixture seed failed');
    fs.copyFileSync(path.join(root, 'fixture.json'), path.join(artifacts, 'fixture.json'));
    fs.writeFileSync(path.join(root, 'fixture-health.txt'), nonce);
    const reservation = net.createServer();
    await new Promise((resolve, reject) => { reservation.once('error', reject); reservation.listen(0, '127.0.0.1', resolve); });
    const port = reservation.address().port;
    await new Promise((resolve) => reservation.close(resolve));
    env.BASE_URL = `http://127.0.0.1:${port}`;
    logFd = fs.openSync(path.join(artifacts, 'php.log'), 'a', 0o600);
    const server = spawn('php', [...phpArgs, '-S', `127.0.0.1:${port}`, '-t', root, 'router.php'],
      { cwd: root, env, detached: true, stdio: ['ignore', logFd, logFd] });
    children.add(server);
    server.once('error', (error) => { console.error(error); });
    // A port accepting connections is insufficient: require our random nonce,
    // so a bind race can never redirect tests to an unrelated existing server.
    let ready = false;
    for (let attempt = 0; attempt < 50; attempt++) {
      if (interrupted) throw new Error('Test run interrupted');
      if (server.exitCode !== null) throw new Error('Private PHP server exited');
      try {
        const response = await fetch(`${env.BASE_URL}/fixture-health.txt`, { signal: AbortSignal.timeout(300) });
        if (response.ok && (await response.text()) === nonce) { ready = true; break; }
      } catch {}
      await new Promise((resolve) => setTimeout(resolve, 100));
    }
    if (!ready) throw new Error('Private PHP server not ready (never reuse an existing server)');
    console.log(`Private E2E server: ${env.BASE_URL}`);
    process.exitCode = await run(process.execPath, [cli, 'test', '-c', 'tests/isolated/playwright.config.cjs', ...process.argv.slice(2)]);
    if (interrupted) process.exitCode = 130;
    if (/PHP (?:Fatal|Parse) error:/.test(fs.readFileSync(path.join(artifacts, 'php.log'), 'utf8'))) {
      throw new Error('PHP fatal/parse error in isolated server log');
    }
  } catch (error) {
    console.error(error);
    process.exitCode = 1;
  } finally {
    stopChildren();
    await new Promise((resolve) => setTimeout(resolve, 300));
    for (const child of children) { try { process.kill(-child.pid, 'SIGKILL'); } catch {} }
    if (logFd !== undefined) fs.closeSync(logFd);
    // Only the freshly created private tree may be removed; reports survive.
    if (fs.readFileSync(path.join(root, '.isolated-e2e'), 'utf8') === nonce) fs.rmSync(root, { recursive: true });
    if (process.env.GITHUB_WORKSPACE) {
      const dest = path.join(process.env.GITHUB_WORKSPACE, 'e2e-artifacts', path.basename(artifacts));
      fs.cpSync(artifacts, dest, { recursive: true });
    }
  }
})();
