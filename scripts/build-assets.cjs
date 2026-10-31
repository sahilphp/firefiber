#!/usr/bin/env node
'use strict';

/**
 * Build Firefiber's production browser assets.
 *
 * The readable sources stay in `js/firefiber.js` and `css/firefiber.css`.
 * This script writes only the optimized artifacts under `dist/` and their
 * metadata. It is a release/build step, never part of an HTTP request.
 *
 * Usage:
 *   node scripts/build-assets.cjs
 *   node scripts/build-assets.cjs --obfuscate
 *   node scripts/build-assets.cjs --check
 *
 * `--obfuscate` requires the optional `javascript-obfuscator` development
 * dependency and applies to JavaScript only. Minification alone is not
 * obfuscation.
 */

const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');

const root = path.resolve(__dirname, '..');
const sourcePath = path.join(root, 'js', 'firefiber.js');
const cssSourcePath = path.join(root, 'css', 'firefiber.css');
const outDir = path.join(root, 'dist');
const outPath = path.join(outDir, 'runtime.min.js');
const cssOutPath = path.join(outDir, 'firefiber.min.css');
const manifestPath = path.join(outDir, 'manifest.json');

const args = new Set(process.argv.slice(2));
const checkOnly = args.has('--check');
const obfuscate = args.has('--obfuscate');

function sha256(value) {
    return crypto.createHash('sha256').update(value).digest('hex');
}

function sri(value) {
    return 'sha384-' + crypto.createHash('sha384').update(value).digest('base64');
}

function readJson(file) {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

function loadTerser() {
    try {
        return require('terser');
    } catch (error) {
        try {
            execFileSync('terser', ['--version'], { stdio: 'ignore' });
            return 'cli';
        } catch (cliError) {
            throw new Error(
                'Terser is required. Install development dependencies with `npm install` '
                + 'or make the `terser` CLI available on PATH.',
            );
        }
    }
}

const terserOptions = {
    ecma: 2018,
    compress: {
        passes: 2,
        drop_debugger: true,
    },
    mangle: {
        toplevel: true,
    },
    format: {
        comments: false,
        ascii_only: true,
    },
};

async function minify(source) {
    const terser = loadTerser();

    if (terser === 'cli') {
        return execFileSync('terser', ['--compress', '--mangle'], {
            input: source,
            encoding: 'utf8',
            maxBuffer: 16 * 1024 * 1024,
        });
    }

    const result = await terser.minify(source, terserOptions);

    if (result.error) {
        throw result.error;
    }

    if (typeof result.code !== 'string' || result.code === '') {
        throw new Error('Terser produced an empty runtime asset.');
    }

    return result.code;
}

function obfuscateCode(code) {
    let JavaScriptObfuscator;

    try {
        JavaScriptObfuscator = require('javascript-obfuscator');
    } catch (error) {
        throw new Error(
            '`--obfuscate` requires javascript-obfuscator. Install development dependencies '
            + 'with `npm install` before running an obfuscated release build.',
        );
    }

    return JavaScriptObfuscator.obfuscate(code, {
        compact: true,
        controlFlowFlattening: false,
        deadCodeInjection: false,
        debugProtection: false,
        disableConsoleOutput: false,
        identifierNamesGenerator: 'hexadecimal',
        renameGlobals: false,
        selfDefending: false,
        stringArray: true,
        stringArrayThreshold: 0.75,
    }).getObfuscatedCode();
}

/**
 * Conservative CSS minifier for Firefiber's own stylesheet. It removes
 * comments and insignificant whitespace without rewriting selectors or
 * values, so the source remains the authority and the output stays
 * predictable.
 */
function minifyCss(source) {
    const code = source
        .replace(/\/\*[\s\S]*?\*\//g, '')
        .replace(/\s+/g, ' ')
        .replace(/\s*([{}:;,])\s*/g, '$1')
        .replace(/;}/g, '}')
        .trim();

    if (code === '') {
        throw new Error('CSS minification produced an empty stylesheet.');
    }

    return code;
}

function checkBuild(source, cssSource) {
    if (!fs.existsSync(outPath) || !fs.existsSync(cssOutPath) || !fs.existsSync(manifestPath)) {
        throw new Error('Production assets are missing. Run `npm run assets:build`.');
    }

    const built = fs.readFileSync(outPath, 'utf8');
    const builtCss = fs.readFileSync(cssOutPath, 'utf8');
    const manifest = readJson(manifestPath);
    const sourceHash = sha256(source);
    const cssSourceHash = sha256(cssSource);

    if (manifest.source_sha256 !== sourceHash) {
        throw new Error('dist/runtime.min.js is stale: the runtime source changed since the last build.');
    }

    if (manifest.css?.source_sha256 !== cssSourceHash) {
        throw new Error('dist/firefiber.min.css is stale: the CSS source changed since the last build.');
    }

    if (manifest.runtime_sha256 !== sha256(built)) {
        throw new Error('dist/runtime.min.js does not match its manifest hash.');
    }

    if (manifest.css?.css_sha256 !== sha256(builtCss)) {
        throw new Error('dist/firefiber.min.css does not match its manifest hash.');
    }

    if (Buffer.byteLength(built) >= Buffer.byteLength(source)) {
        throw new Error('dist/runtime.min.js is not smaller than the readable runtime source.');
    }

    if (Buffer.byteLength(builtCss) >= Buffer.byteLength(cssSource)) {
        throw new Error('dist/firefiber.min.css is not smaller than the readable CSS source.');
    }

    process.stdout.write(
        `Firefiber assets are current (${Buffer.byteLength(built)} bytes JS, ${Buffer.byteLength(builtCss)} bytes CSS, ${manifest.obfuscated ? 'obfuscated' : 'minified'}).\n`,
    );
}

async function build() {
    const source = fs.readFileSync(sourcePath, 'utf8');
    const cssSource = fs.readFileSync(cssSourcePath, 'utf8');
    fs.mkdirSync(outDir, { recursive: true });

    let code = await minify(source);

    if (obfuscate) {
        code = obfuscateCode(code);
    }

    code = code.endsWith('\n') ? code : code + '\n';
    const cssCode = minifyCss(cssSource) + '\n';

    const manifest = {
        name: 'runtime.min.js',
        source: 'js/firefiber.js',
        source_sha256: sha256(source),
        runtime_sha256: sha256(code),
        runtime_sri: sri(code),
        runtime_bytes: Buffer.byteLength(code),
        minified: true,
        obfuscated: obfuscate,
        css: {
            name: 'firefiber.min.css',
            source: 'css/firefiber.css',
            source_sha256: sha256(cssSource),
            css_sha256: sha256(cssCode),
            css_sri: sri(cssCode),
            css_bytes: Buffer.byteLength(cssCode),
            minified: true,
        },
        aliases: {
            '@fiberscripts': 'runtime.min.js',
            '@fiberstyles': 'firefiber.min.css',
        },
    };

    fs.writeFileSync(outPath, code, 'utf8');
    fs.writeFileSync(cssOutPath, cssCode, 'utf8');
    fs.writeFileSync(manifestPath, JSON.stringify(manifest, null, 2) + '\n', 'utf8');

    process.stdout.write(
        `Built dist/runtime.min.js (${manifest.runtime_bytes} bytes, ${obfuscate ? 'obfuscated' : 'minified'}).\n`,
    );
    process.stdout.write(`Built dist/firefiber.min.css (${manifest.css.css_bytes} bytes, minified).\n`);
}

(async function main() {
    const source = fs.readFileSync(sourcePath, 'utf8');
    const cssSource = fs.readFileSync(cssSourcePath, 'utf8');

    if (checkOnly) {
        checkBuild(source, cssSource);
        return;
    }

    await build();
})().catch((error) => {
    process.stderr.write(`Asset build failed: ${error.message}\n`);
    process.exitCode = 1;
});
