    </section>
</main>
<div id="toast" class="toast" hidden></div>
<div id="modal" class="modal" hidden>
    <div class="modal-card" id="modalCard"></div>
</div>
<script>
window.ASTERA = Object.assign(window.ASTERA || {}, {
    csrf: <?= json_encode(csrf_token()) ?>,
    dept: <?= json_encode(current_dept_id()) ?>,
    isSuper: <?= is_super() ? 'true' : 'false' ?>,
    depts: <?= json_encode(departments(), JSON_UNESCAPED_UNICODE) ?>,
    dests: <?= json_encode(dest_catalog(current_dept_id()), JSON_UNESCAPED_UNICODE) ?>,
    destTypes: <?= json_encode(dest_types(), JSON_UNESCAPED_UNICODE) ?>,
    sounds: <?= json_encode(panel_sounds(current_dept_id()), JSON_UNESCAPED_UNICODE) ?>,
    uploadedSoundSets: <?= json_encode(array_reduce(departments(), static function ($sets, $dept) {
        $id = (string) ($dept['id'] ?? '');
        if ($id !== '') {
            $sets[$id] = panel_uploaded_sounds($id);
        }
        return $sets;
    }, []), JSON_UNESCAPED_UNICODE) ?>,
    soundSets: <?= json_encode(array_reduce(departments(), static function ($sets, $dept) {
        $id = (string) ($dept['id'] ?? '');
        if ($id !== '') {
            $sets[$id] = panel_sounds($id);
        }
        return $sets;
    }, []), JSON_UNESCAPED_UNICODE) ?>,
    pbxHost: <?= json_encode(PBX_HOST) ?>,
    ws: <?= json_encode(pbx_ws_url()) ?>,
    wss: <?= json_encode(pbx_wss_url()) ?>
});
</script>
<script src="assets/js/app.js?v=66"></script>
</body>
</html>
