const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const view = fs.readFileSync('resources/views/backups/index.blade.php', 'utf8');
test('restoration is visibly disabled without an operative upload or submit control', () => {
    assert.match(view, /Restauración temporalmente deshabilitada/);
    assert.match(view, /<p[^>]+role="status"[^>]*>[^<]*deshabilitada por seguridad/);
    assert.match(view, /<button[^>]+\bdisabled\b[^>]*>[\s\S]*?Restauración no disponible\s*<\/button>/);
    assert.doesNotMatch(view, /<form\b|type="file"|type="submit"|restoreModalOpen|\/backups\/restaurar/);
});
test('query and download remain available without a recovery certification', () => {
    assert.match(view, /url\('\/backups\/descargar'\)/);
    assert.match(view, /El destino de la exportación y su recuperación requieren verificación independiente/);
    assert.doesNotMatch(view, /previamente descargado para restaurar|Estoy seguro, Restaurar/);
});
