(function () {
    'use strict';

    var config = window.LunaraReviewDraftImport || {};
    var roleLabels = {
        theme_echo: 'Theme Echo',
        counter_program: 'Counter-Program',
        career_context: 'Career Context'
    };

    function text(value) {
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function string(name, fallback) {
        return config.strings && config.strings[name] ? text(config.strings[name]) : fallback;
    }

    function select(root, selector) {
        return root ? root.querySelector(selector) : null;
    }

    function element(tagName, className, content) {
        var node = document.createElement(tagName);
        if (className) {
            node.className = className;
        }
        if (content !== null && typeof content !== 'undefined') {
            node.textContent = text(content);
        }
        return node;
    }

    function byteLength(value) {
        if (window.TextEncoder) {
            return new window.TextEncoder().encode(value).length;
        }
        return new window.Blob([value]).size;
    }

    function maxBytes() {
        var configured = Number(config.maxBytes || 0);
        return configured > 0 ? configured : 1048576;
    }

    function fieldValue(field) {
        if (field.type === 'checkbox' || field.type === 'radio') {
            return field.checked ? '1' : '0';
        }
        return text(field.value);
    }

    function editorFields(root) {
        var form = document.getElementById('post');
        if (!form) {
            return [];
        }
        return Array.prototype.filter.call(
            form.querySelectorAll('input, textarea, select'),
            function (field) {
                var type = text(field.type).toLowerCase();
                return !root.contains(field)
                    && !field.disabled
                    && ['button', 'submit', 'reset', 'file'].indexOf(type) === -1;
            }
        );
    }

    function captureEditorState(root) {
        root._lunaraEditorState = editorFields(root).map(function (field) {
            return { field: field, value: fieldValue(field) };
        });
        root._lunaraFormDirty = false;
    }

    function bindTinyMceEditor(root, editor) {
        if (!editor || editor._lunaraReviewImportBound) {
            return;
        }
        editor._lunaraReviewImportBound = true;
        editor.on('input change undo redo', function () {
            root._lunaraFormDirty = true;
            root._lunaraFormGeneration = Number(root._lunaraFormGeneration || 0) + 1;
        });
    }

    function bindTinyMceEditors(root) {
        var editors = window.tinymce && Array.isArray(window.tinymce.editors)
            ? window.tinymce.editors
            : [];
        editors.forEach(function (editor) {
            bindTinyMceEditor(root, editor);
        });

        if (window.tinymce && typeof window.tinymce.on === 'function' && !root._lunaraTinyMceListener) {
            root._lunaraTinyMceListener = true;
            window.tinymce.on('AddEditor', function (event) {
                bindTinyMceEditor(root, event && event.editor);
            });
        }
    }

    function bindFormDirtyState(root) {
        var form = document.getElementById('post');
        var markDirty = function (event) {
            if (!root.contains(event.target)) {
                root._lunaraFormDirty = true;
                root._lunaraFormGeneration = Number(root._lunaraFormGeneration || 0) + 1;
            }
        };
        if (form) {
            form.addEventListener('input', markDirty, true);
            form.addEventListener('change', markDirty, true);
        }
        bindTinyMceEditors(root);
    }

    function bindSaveLifecycle(root) {
        var data = window.wp && window.wp.data;
        var editorStore = data && data.select('core/editor');
        var wasSaving = false;
        var wasAutosaving = false;
        var saveStartGeneration = 0;

        if (!data || typeof data.subscribe !== 'function' || !editorStore) {
            return;
        }

        root._lunaraSaveUnsubscribe = data.subscribe(function () {
            var saving = typeof editorStore.isSavingPost === 'function' && editorStore.isSavingPost();
            var autosaving = typeof editorStore.isAutosavingPost === 'function' && editorStore.isAutosavingPost();
            var saveSucceeded = typeof editorStore.didPostSaveRequestSucceed === 'function'
                && editorStore.didPostSaveRequestSucceed();
            var dirty = typeof editorStore.isEditedPostDirty === 'function' && editorStore.isEditedPostDirty();
            var formGeneration = Number(root._lunaraFormGeneration || 0);

            if (!wasSaving && saving && !autosaving) {
                saveStartGeneration = formGeneration;
            }

            if (
                wasSaving
                && !saving
                && !wasAutosaving
                && saveSucceeded
                && !dirty
                && formGeneration === saveStartGeneration
            ) {
                window.setTimeout(function () {
                    if (Number(root._lunaraFormGeneration || 0) !== saveStartGeneration) {
                        return;
                    }
                    captureEditorState(root);
                    bindTinyMceEditors(root);
                    clearPreview(root);
                    setAlert(root, '');
                    setStatus(root, 'Draft saved. Preview the import again against the current fields.');
                }, 0);
            }
            wasSaving = saving;
            wasAutosaving = autosaving;
        });
    }

    function hasUnsavedEditorChanges(root) {
        var editorStore;
        var changed;

        if (root._lunaraFormDirty) {
            return true;
        }

        try {
            editorStore = window.wp && window.wp.data && window.wp.data.select('core/editor');
            if (editorStore && typeof editorStore.isEditedPostDirty === 'function' && editorStore.isEditedPostDirty()) {
                return true;
            }
        } catch (error) {
            editorStore = null;
        }

        changed = (root._lunaraEditorState || []).some(function (entry) {
            return entry.field.isConnected && fieldValue(entry.field) !== entry.value;
        });
        return changed;
    }

    function requireSavedEditor(root) {
        if (!hasUnsavedEditorChanges(root)) {
            return true;
        }
        clearPreview(root);
        setStatus(root, '');
        setAlert(root, string('saveFirst', 'Save the Review draft before importing so no unsaved editor changes can be lost.'));
        return false;
    }

    function errorMessage(error) {
        return error && error.message
            ? text(error.message)
            : string('failed', 'The import could not be completed. Review the warning and try again.');
    }

    function setStatus(root, message) {
        var status = select(root, '[data-lunara-review-import-status]');
        if (status) {
            status.textContent = text(message);
        }
    }

    function setAlert(root, message) {
        var alert = select(root, '[data-lunara-review-import-alert]');
        if (!alert) {
            return;
        }
        alert.textContent = text(message);
        alert.hidden = !message;
    }

    function setBusy(root, busy) {
        var spinner = select(root, '[data-lunara-review-import-spinner]');
        var previewButton = select(root, '[data-lunara-review-import-preview]');
        var applyButton = select(root, '[data-lunara-review-import-apply]');
        var fileInput = select(root, '[data-lunara-review-import-file]');
        var textarea = select(root, '[data-lunara-review-import-html]');

        root.setAttribute('aria-busy', busy ? 'true' : 'false');
        if (spinner) {
            spinner.classList.toggle('is-active', Boolean(busy));
        }
        [previewButton, fileInput, textarea].forEach(function (control) {
            if (control) {
                control.disabled = Boolean(busy);
            }
        });
        if (applyButton) {
            applyButton.disabled = Boolean(busy) || !canApply(root);
        }
    }

    function canApply(root) {
        var preview = root._lunaraPreview;
        return Boolean(
            preview
            && preview.valid === true
            && preview.existing
            && (preview.existing.content === false || preview.existing.recoverable === true)
            && root._lunaraSource
        );
    }

    function clearPreview(root) {
        var result = select(root, '[data-lunara-review-import-result]');
        var summary = select(root, '[data-lunara-review-import-summary]');
        var pairings = select(root, '[data-lunara-review-import-pairings]');
        var warnings = select(root, '[data-lunara-review-import-warnings]');
        var applyButton = select(root, '[data-lunara-review-import-apply]');

        root._lunaraGeneration = Number(root._lunaraGeneration || 0) + 1;
        root._lunaraPreview = null;
        root._lunaraSource = '';
        [summary, pairings, warnings].forEach(function (container) {
            if (container) {
                container.replaceChildren();
            }
        });
        if (result) {
            result.hidden = true;
        }
        if (applyButton) {
            applyButton.disabled = true;
        }
    }

    function beginRequest(root) {
        root._lunaraGeneration = Number(root._lunaraGeneration || 0) + 1;
        return root._lunaraGeneration;
    }

    function requestIsCurrent(root, generation) {
        return root._lunaraGeneration === generation;
    }

    function readFile(file) {
        return new Promise(function (resolve, reject) {
            var reader = new window.FileReader();
            reader.addEventListener('load', function () {
                resolve(text(reader.result));
            });
            reader.addEventListener('error', function () {
                reject(new Error('The selected HTML file could not be read.'));
            });
            reader.addEventListener('abort', function () {
                reject(new Error('Reading the selected HTML file was cancelled.'));
            });
            reader.readAsText(file);
        });
    }

    function sourceFor(root) {
        var textarea = select(root, '[data-lunara-review-import-html]');
        var fileInput = select(root, '[data-lunara-review-import-file]');
        var pasted = textarea ? text(textarea.value) : '';
        var file = fileInput && fileInput.files ? fileInput.files[0] : null;

        if (pasted.trim()) {
            if (byteLength(pasted) > maxBytes()) {
                return Promise.reject(new Error(string('tooLarge', 'That draft is larger than the one-megabyte import limit.')));
            }
            return Promise.resolve(pasted);
        }

        if (!file) {
            return Promise.reject(new Error(string('choose', 'Choose or paste an HTML draft first.')));
        }
        if (!/\.html?$/i.test(text(file.name))) {
            return Promise.reject(new Error('Choose an .html or .htm file.'));
        }
        if (file.size > maxBytes()) {
            return Promise.reject(new Error(string('tooLarge', 'That draft is larger than the one-megabyte import limit.')));
        }

        setStatus(root, string('reading', 'Reading the draft...'));
        return readFile(file).then(function (source) {
            if (byteLength(source) > maxBytes()) {
                throw new Error(string('tooLarge', 'That draft is larger than the one-megabyte import limit.'));
            }
            return source;
        });
    }

    function post(endpoint, payload) {
        var restBase = text(config.restBase).replace(/\/?$/, '/');
        return window.fetch(restBase + endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': text(config.nonce)
            },
            body: JSON.stringify(payload)
        }).then(function (response) {
            return response.json().catch(function () {
                return {};
            }).then(function (body) {
                if (!response.ok) {
                    var requestError = new Error(body && body.message ? body.message : string('failed', 'The import could not be completed.'));
                    requestError.details = body && body.data ? body.data : {};
                    throw requestError;
                }
                return body;
            });
        });
    }

    function appendFact(list, label, value, modifier) {
        var item = element('div', 'lunara-review-import-fact' + (modifier ? ' ' + modifier : ''));
        item.appendChild(element('dt', '', label));
        item.appendChild(element('dd', '', value || 'Not supplied'));
        list.appendChild(item);
    }

    function appendMappedFact(list, label, value, exists) {
        var display = value || 'Not supplied';
        if (exists) {
            display += ' (existing value preserved)';
        }
        appendFact(list, label, display, exists ? 'is-preserved' : '');
    }

    function metadataValue(metadata, names) {
        var value = '';
        names.some(function (name) {
            if (metadata[name]) {
                value = text(metadata[name]);
                return true;
            }
            return false;
        });
        return value;
    }

    function metadataLabel(name) {
        return text(name).replace(/_/g, ' ').replace(/\b\w/g, function (letter) {
            return letter.toUpperCase();
        });
    }

    function renderSummary(root, response) {
        var container = select(root, '[data-lunara-review-import-summary]');
        var summary = response.summary || {};
        var existing = response.existing || {};
        var existingFields = existing.fields || {};
        var metadata = summary.metadata || {};
        var mappedMetadata = ['director', 'director_writer', 'runtime', 'studio_distributor', 'studio'];
        var retainedMetadata;
        var facts;
        var existingNote;

        if (!container) {
            return;
        }
        container.replaceChildren();
        container.appendChild(element('h3', 'lunara-review-import-section-title', 'Import summary'));
        facts = element('dl', 'lunara-review-import-facts');
        appendFact(facts, 'Review', text(summary.title) + (summary.year ? ' (' + text(summary.year) + ')' : ''));
        appendMappedFact(facts, 'IMDb', summary.imdbId, existingFields.imdbId);
        appendFact(facts, 'Body', text(summary.paragraphCount) + ' native paragraph' + (Number(summary.paragraphCount) === 1 ? '' : 's'));
        appendMappedFact(facts, 'Standfirst', summary.standfirst, existingFields.standfirst);
        appendFact(facts, 'Excerpt', summary.excerpt);
        appendMappedFact(facts, 'Score', summary.score, existingFields.score);
        appendMappedFact(facts, 'Year', summary.year, existingFields.year);
        appendMappedFact(facts, 'Where to Watch', summary.whereToWatch, existingFields.whereToWatch);
        appendMappedFact(facts, 'Director', metadataValue(metadata, ['director', 'director_writer']), existingFields.director);
        appendMappedFact(facts, 'Runtime', metadata.runtime, existingFields.runtime);
        appendMappedFact(facts, 'Studio', metadataValue(metadata, ['studio_distributor', 'studio']), existingFields.studio);
        appendFact(
            facts,
            'Review draft',
            existing.alreadyImported
                ? 'This exact source is already imported'
                : (existing.recoverable
                    ? 'Imported body found - same-source recovery ready'
                    : (existing.content ? 'Contains body content - import blocked' : 'Body is empty - ready to import')),
            existing.content && !existing.recoverable && !existing.alreadyImported ? 'is-blocked' : 'is-ready'
        );
        container.appendChild(facts);

        if (existing.title || existing.excerpt) {
            existingNote = element(
                'p',
                'lunara-review-import-existing-note',
                'Existing ' + [existing.title ? 'title' : '', existing.excerpt ? 'excerpt' : ''].filter(Boolean).join(' and ') + ' will be preserved.'
            );
            container.appendChild(existingNote);
        }

        retainedMetadata = Object.keys(metadata).filter(function (name) {
            return mappedMetadata.indexOf(name) === -1;
        });
        if (retainedMetadata.length) {
            container.appendChild(element(
                'p',
                'lunara-review-import-existing-note',
                'Retained in the protected import record: ' + retainedMetadata.map(metadataLabel).join(', ') + '.'
            ));
        }
    }

    function resolutionText(resolution) {
        var status = text(resolution && resolution.status);
        if (status === 'published') {
            return 'Published local Movie found' + (resolution.movieId ? ' (ID ' + text(resolution.movieId) + ')' : '') + '.';
        }
        if (status === 'conflict') {
            return 'Multiple published Movies use this IMDb ID. Resolve the local conflict in Debrief Studio.';
        }
        return 'No published local Movie found. The pairing text and reason will remain editable.';
    }

    function renderPairings(root, response) {
        var container = select(root, '[data-lunara-review-import-pairings]');
        var pairings = response.pairings || {};
        var resolutions = response.resolutions || {};
        var existingDebrief = response.existing && response.existing.debrief
            ? response.existing.debrief
            : {};
        var unresolved = false;

        if (!container) {
            return;
        }
        container.replaceChildren();
        container.appendChild(element('h3', 'lunara-review-import-section-title', 'Debrief pairings'));

        Object.keys(roleLabels).forEach(function (role) {
            var pairing = pairings[role] || {};
            var resolution = resolutions[role] || { status: 'missing' };
            var row = element('article', 'lunara-review-import-pairing');
            var heading = element('div', 'lunara-review-import-pairing-head');
            var title = text(pairing.title) + (pairing.year ? ' (' + text(pairing.year) + ')' : '');
            var state = element('span', 'lunara-review-import-resolution', resolutionText(resolution));
            var preserved = existingDebrief[role] || {};
            var preservedParts = [];

            state.setAttribute('data-status', text(resolution.status) || 'missing');
            heading.appendChild(element('span', 'lunara-review-import-role', roleLabels[role]));
            heading.appendChild(element('strong', 'lunara-review-import-film', title || 'Pairing not supplied'));
            row.appendChild(heading);
            row.appendChild(element('p', 'lunara-review-import-imdb', text(pairing.imdb_id || resolution.imdbId)));
            row.appendChild(element('p', 'lunara-review-import-reason', pairing.reason));
            row.appendChild(state);
            if (preserved.movie) {
                preservedParts.push('existing Movie');
            }
            if (preserved.reason) {
                preservedParts.push('existing reason');
            }
            if (preservedParts.length) {
                row.appendChild(element(
                    'p',
                    'lunara-review-import-preserved',
                    'Preserved on apply: ' + preservedParts.join(' and ') + '.'
                ));
            }
            container.appendChild(row);

            if (text(resolution.status) !== 'published') {
                unresolved = true;
            }
        });

        if (unresolved) {
            container.appendChild(element(
                'p',
                'lunara-review-import-resolution-note',
                string('unresolved', 'Missing local Movie records remain editable in Debrief Studio.')
            ));
        }
    }

    function warningText(code) {
        var messages = {
            missing_metadata: 'No Lunara metadata section was found.',
            empty_metadata: 'The Lunara metadata section was empty.',
            unterminated_metadata_comment: 'The metadata comment was safely closed at the end of the file.',
            missing_excerpt: 'No card excerpt was found; the excerpt will remain empty.',
            unsupported_element_unwrapped: 'Unsupported wrapper markup was removed while preserving its readable text.',
            duplicate_pairing_theme_echo: 'A duplicate Theme Echo entry was ignored.',
            duplicate_pairing_counter_program: 'A duplicate Counter-Program entry was ignored.',
            duplicate_pairing_career_context: 'A duplicate Career Context entry was ignored.'
        };
        var normalized = text(code);
        var fallback;

        if (messages[normalized]) {
            return messages[normalized];
        }
        fallback = normalized.replace(/_/g, ' ').trim();
        return fallback ? fallback.charAt(0).toUpperCase() + fallback.slice(1) + '.' : 'The parser reported an unspecified warning.';
    }

    function renderWarnings(root, response) {
        var container = select(root, '[data-lunara-review-import-warnings]');
        var warnings = Array.isArray(response.warnings) ? response.warnings : [];
        var list;

        if (!container) {
            return;
        }
        container.replaceChildren();
        container.appendChild(element('h3', 'lunara-review-import-section-title', 'Import notes'));
        if (!warnings.length) {
            container.appendChild(element('p', 'lunara-review-import-no-warnings', 'No structural warnings.'));
            return;
        }
        list = element('ul', 'lunara-review-import-warning-list');
        warnings.forEach(function (warning) {
            list.appendChild(element('li', '', warningText(warning)));
        });
        container.appendChild(list);
    }

    function showPreview(root, response, source) {
        var result = select(root, '[data-lunara-review-import-result]');
        var applyButton = select(root, '[data-lunara-review-import-apply]');
        var blocked = Boolean(
            response.existing
            && response.existing.content
            && !response.existing.recoverable
            && !response.existing.alreadyImported
        );

        root._lunaraPreview = response;
        root._lunaraSource = source;
        renderSummary(root, response);
        renderPairings(root, response);
        renderWarnings(root, response);
        if (result) {
            result.hidden = false;
        }
        if (applyButton) {
            applyButton.disabled = !canApply(root);
        }

        setStatus(
            root,
            response.existing && response.existing.alreadyImported
                ? string('already', 'This exact source file has already been imported into this Review.')
                : string('ready', 'Preview ready. Review the mappings, then apply them to this draft.')
        );
        setAlert(
            root,
            blocked
                ? 'This Review already has body content. Move the existing prose or start a fresh draft before importing.'
                : ''
        );
    }

    function issueList(error) {
        var details = error && error.details ? error.details : {};
        var issues = [];
        if (Array.isArray(details.errors)) {
            issues = issues.concat(details.errors);
        }
        if (Array.isArray(details.warnings)) {
            issues = issues.concat(details.warnings);
        }
        return issues.map(warningText);
    }

    function showRequestError(root, error) {
        var issues = issueList(error);
        var message = errorMessage(error);
        if (issues.length) {
            message += ' ' + issues.join(' ');
        }
        setStatus(root, '');
        setAlert(root, message);
    }

    function preview(root) {
        var generation;

        clearPreview(root);
        setAlert(root, '');
        if (!requireSavedEditor(root)) {
            return;
        }
        generation = beginRequest(root);
        setBusy(root, true);

        sourceFor(root).then(function (source) {
            if (!requestIsCurrent(root, generation)) {
                return null;
            }
            setStatus(root, string('previewing', 'Checking structure and field mappings...'));
            return post('preview', {
                review_id: Number(config.reviewId || root.getAttribute('data-review-id') || 0),
                html: source
            }).then(function (response) {
                return { response: response, source: source };
            });
        }).then(function (result) {
            if (!result || !requestIsCurrent(root, generation)) {
                return;
            }
            setBusy(root, false);
            if (!result.response || result.response.valid !== true) {
                throw new Error(string('failed', 'The import could not be completed.'));
            }
            showPreview(root, result.response, result.source);
        }).catch(function (error) {
            if (!requestIsCurrent(root, generation)) {
                return;
            }
            setBusy(root, false);
            clearPreview(root);
            showRequestError(root, error);
        });
    }

    function apply(root) {
        var generation;
        var source = root._lunaraSource;
        var applyButton = select(root, '[data-lunara-review-import-apply]');

        if (!canApply(root)) {
            return;
        }
        if (!requireSavedEditor(root)) {
            return;
        }
        setAlert(root, '');
        setStatus(root, string('applying', 'Creating native blocks and filling empty Lunara fields...'));
        generation = beginRequest(root);
        setBusy(root, true);

        post('apply', {
            review_id: Number(config.reviewId || root.getAttribute('data-review-id') || 0),
            html: source
        }).then(function (response) {
            if (!requestIsCurrent(root, generation)) {
                return;
            }
            setBusy(root, false);
            if (!response || response.valid !== true) {
                throw new Error(string('failed', 'The import could not be completed.'));
            }
            if (response && response.alreadyImported) {
                root._lunaraPreview = null;
                if (applyButton) {
                    applyButton.disabled = true;
                }
                setStatus(root, string('already', 'This exact source file has already been imported into this Review.'));
                return;
            }
            setStatus(root, string('applied', 'Import complete. Reloading the Review editor...'));
            window.setTimeout(function () {
                window.location.reload();
            }, 500);
        }).catch(function (error) {
            if (!requestIsCurrent(root, generation)) {
                return;
            }
            setBusy(root, false);
            showRequestError(root, error);
            if (applyButton) {
                applyButton.focus();
            }
        });
    }

    function initialize(root) {
        var fileInput = select(root, '[data-lunara-review-import-file]');
        var textarea = select(root, '[data-lunara-review-import-html]');
        var previewButton = select(root, '[data-lunara-review-import-preview]');
        var applyButton = select(root, '[data-lunara-review-import-apply]');

        if (!previewButton || !applyButton) {
            return;
        }

        captureEditorState(root);
        bindFormDirtyState(root);
        bindSaveLifecycle(root);

        if (fileInput) {
            fileInput.addEventListener('change', function () {
                if (fileInput.files && fileInput.files.length && textarea) {
                    textarea.value = '';
                }
                clearPreview(root);
                setStatus(root, '');
                setAlert(root, '');
            });
        }
        if (textarea) {
            textarea.addEventListener('input', function () {
                if (textarea.value && fileInput) {
                    fileInput.value = '';
                }
                clearPreview(root);
                setStatus(root, '');
                setAlert(root, '');
            });
        }
        previewButton.addEventListener('click', function () {
            preview(root);
        });
        applyButton.addEventListener('click', function () {
            apply(root);
        });
    }

    function initializeAll() {
        document.querySelectorAll('[data-lunara-review-import]').forEach(initialize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initializeAll);
    } else {
        initializeAll();
    }
}());
