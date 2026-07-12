(function () {
    'use strict';

    var config = window.LunaraMovieImportAdmin || {};
    var activeLauncher = null;

    function select(root, selector) {
        return root ? root.querySelector(selector) : null;
    }

    function text(value) {
        return value === null || typeof value === 'undefined' ? '' : String(value);
    }

    function beginRequest(launcher) {
        var generation = Number(launcher && launcher._lunaraRequestGeneration || 0) + 1;
        if (launcher) {
            launcher._lunaraRequestGeneration = generation;
        }
        return generation;
    }

    function requestIsCurrent(launcher, generation) {
        return Boolean(launcher) && launcher._lunaraRequestGeneration === generation;
    }

    function setStatus(launcher, message) {
        var status = select(launcher, '[data-lunara-movie-import-status]');
        if (status) {
            status.textContent = text(message);
        }
    }

    function setAlert(launcher, message) {
        var alert = select(launcher, '[data-lunara-movie-import-alert]');
        if (!alert) {
            return;
        }
        alert.textContent = text(message);
        alert.hidden = !message;
    }

    function setBusy(launcher, busy) {
        var dialog = select(launcher, '[data-lunara-movie-import-dialog]');
        if (!dialog) {
            return;
        }
        dialog.setAttribute('aria-busy', busy ? 'true' : 'false');
        dialog.querySelectorAll('button, input').forEach(function (control) {
            control.disabled = Boolean(busy);
        });
        var close = select(dialog, '[data-lunara-movie-import-close]');
        if (close) {
            close.disabled = false;
        }
    }

    function resetLauncher(launcher) {
        beginRequest(launcher);
        var result = select(launcher, '[data-lunara-movie-import-result]');
        var editLink = select(launcher, '[data-lunara-movie-edit-link]');
        var importButton = select(launcher, '[data-lunara-movie-import-draft]');
        if (result) {
            result.hidden = true;
            result.removeAttribute('data-imdb-id');
        }
        if (editLink) {
            editLink.hidden = true;
            editLink.removeAttribute('href');
        }
        if (importButton) {
            importButton.hidden = false;
            importButton.textContent = (config.strings && config.strings.createDraft) || 'Create draft dossier';
        }
        setStatus(launcher, '');
        setAlert(launcher, '');
        setBusy(launcher, false);
        launcher.setAttribute('data-state', 'local-first');
    }

    function errorMessage(error) {
        if (error && error.message) {
            return error.message;
        }
        return (config.strings && config.strings.requestFail) || 'The request could not be completed.';
    }

    function post(endpoint, payload) {
        return window.fetch(text(config.restBase) + endpoint, {
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
                    var requestError = new Error(body && body.message ? body.message : errorMessage());
                    requestError.details = body && body.data ? body.data : {};
                    throw requestError;
                }
                return body;
            });
        });
    }

    function payloadFor(launcher, imdbId) {
        return {
            review_id: Number(config.reviewId || 0),
            role: text(launcher.getAttribute('data-role')),
            imdb_id: imdbId
        };
    }

    function normalizedImdb(value) {
        var match = text(value).trim().toLowerCase().match(/\b(tt[0-9]{6,9})\b/);
        return match ? match[1] : '';
    }

    function showCandidate(launcher, candidate) {
        var result = select(launcher, '[data-lunara-movie-import-result]');
        var title = select(launcher, '[data-lunara-candidate-title]');
        var meta = select(launcher, '[data-lunara-candidate-meta]');
        var overview = select(launcher, '[data-lunara-candidate-overview]');
        var pieces = [];
        var importButton = select(launcher, '[data-lunara-movie-import-draft]');
        var editLink = select(launcher, '[data-lunara-movie-edit-link]');

        if (!result || !title || !candidate) {
            return;
        }
        if (candidate.year) {
            pieces.push(text(candidate.year));
        }
        pieces.push(text(candidate.imdb_title_id));
        if (candidate.runtime) {
            pieces.push(text(candidate.runtime));
        }
        if (candidate.directors) {
            pieces.push('Directed by ' + text(candidate.directors));
        }

        title.textContent = text(candidate.title);
        meta.textContent = pieces.filter(Boolean).join(' | ');
        overview.textContent = text(candidate.overview);
        overview.hidden = !candidate.overview;
        result.setAttribute('data-imdb-id', text(candidate.imdb_title_id));
        result.hidden = false;
        if (importButton) {
            importButton.hidden = candidate.can_import === false;
            importButton.textContent = candidate.local
                ? ((config.strings && config.strings.enrichDraft) || 'Enrich existing draft')
                : ((config.strings && config.strings.createDraft) || 'Create draft dossier');
        }
        if (editLink) {
            editLink.hidden = true;
            editLink.removeAttribute('href');
            if (candidate.edit_url) {
                editLink.href = text(candidate.edit_url);
                editLink.hidden = false;
            }
        }
        if (candidate.local && candidate.can_import !== false) {
            setStatus(launcher, (config.strings && config.strings.draftReady) || 'An existing draft can be enriched.');
            launcher.setAttribute('data-state', 'candidate-local');
        } else if (candidate.local) {
            setStatus(launcher, (config.strings && config.strings.localFound) || 'This film is already in the local library.');
            launcher.setAttribute('data-state', 'local');
        } else {
            launcher.setAttribute('data-state', 'candidate');
        }
        title.focus();
    }

    function showImported(launcher, movie) {
        var importButton = select(launcher, '[data-lunara-movie-import-draft]');
        var editLink = select(launcher, '[data-lunara-movie-edit-link]');
        var importedMessage = (config.strings && config.strings.imported) || 'Draft Film Dossier created.';

        if (importButton) {
            importButton.hidden = true;
        }
        if (editLink && movie && movie.edit_url) {
            editLink.href = text(movie.edit_url);
            editLink.hidden = false;
            editLink.focus();
        }
        setStatus(launcher, importedMessage);
        launcher.setAttribute('data-state', 'imported');
    }

    function showRecovery(launcher, movie) {
        var result = select(launcher, '[data-lunara-movie-import-result]');
        var title = select(launcher, '[data-lunara-candidate-title]');
        var meta = select(launcher, '[data-lunara-candidate-meta]');
        var overview = select(launcher, '[data-lunara-candidate-overview]');
        var importButton = select(launcher, '[data-lunara-movie-import-draft]');
        var editLink = select(launcher, '[data-lunara-movie-edit-link]');

        if (!result || !title || !movie || !movie.edit_url) {
            return false;
        }

        title.textContent = text(movie.title) || 'Existing Film Dossier';
        meta.textContent = text(movie.status) === 'draft' ? 'Draft Film Dossier' : 'Existing Film Dossier';
        if (overview) {
            overview.textContent = '';
            overview.hidden = true;
        }
        result.removeAttribute('data-imdb-id');
        result.hidden = false;
        if (importButton) {
            importButton.hidden = true;
        }
        if (editLink) {
            editLink.href = text(movie.edit_url);
            editLink.hidden = false;
            editLink.focus();
        }
        launcher.setAttribute('data-state', 'recovery');
        return true;
    }

    document.addEventListener('click', function (event) {
        var open = event.target.closest('[data-lunara-movie-import-open]');
        var close = event.target.closest('[data-lunara-movie-import-close]');
        var importButton = event.target.closest('[data-lunara-movie-import-draft]');

        if (open) {
            var launcher = open.closest('[data-lunara-movie-import-launcher]');
            var dialog = select(launcher, '[data-lunara-movie-import-dialog]');
            if (!launcher || !dialog || typeof dialog.showModal !== 'function') {
                return;
            }
            resetLauncher(launcher);
            activeLauncher = launcher;
            launcher._lunaraReturnFocus = open;
            dialog.showModal();
            var input = select(dialog, '[data-lunara-imdb-input]');
            if (input) {
                window.setTimeout(function () { input.focus(); }, 0);
            }
            return;
        }

        if (close) {
            var closeDialog = close.closest('dialog');
            if (closeDialog) {
                closeDialog.close('cancel');
            }
            return;
        }

        if (importButton) {
            var importLauncher = importButton.closest('[data-lunara-movie-import-launcher]');
            var result = select(importLauncher, '[data-lunara-movie-import-result]');
            var imdbId = result ? text(result.getAttribute('data-imdb-id')) : '';
            var enriching = importLauncher && importLauncher.getAttribute('data-state') === 'candidate-local';
            if (!imdbId) {
                return;
            }

            setAlert(importLauncher, '');
            setStatus(
                importLauncher,
                enriching
                    ? ((config.strings && config.strings.enrichBusy) || 'Enriching the existing draft Film Dossier...')
                    : ((config.strings && config.strings.importBusy) || 'Creating the draft Film Dossier...')
            );
            setBusy(importLauncher, true);
            var importGeneration = beginRequest(importLauncher);
            post('import', payloadFor(importLauncher, imdbId)).then(function (response) {
                if (!requestIsCurrent(importLauncher, importGeneration)) {
                    return;
                }
                setBusy(importLauncher, false);
                showImported(importLauncher, response.movie || {});
            }).catch(function (error) {
                if (!requestIsCurrent(importLauncher, importGeneration)) {
                    return;
                }
                setBusy(importLauncher, false);
                setStatus(importLauncher, '');
                setAlert(importLauncher, errorMessage(error));
                if (!showRecovery(importLauncher, error && error.details && error.details.movie)) {
                    importButton.focus();
                }
            });
        }
    });

    document.addEventListener('submit', function (event) {
        var form = event.target.closest('[data-lunara-movie-lookup-form]');
        if (!form) {
            return;
        }
        event.preventDefault();

        var launcher = form.closest('[data-lunara-movie-import-launcher]');
        var input = select(form, '[data-lunara-imdb-input]');
        var imdbId = normalizedImdb(input ? input.value : '');
        var result = select(launcher, '[data-lunara-movie-import-result]');
        var importButton = select(launcher, '[data-lunara-movie-import-draft]');

        if (!imdbId) {
            setAlert(launcher, (config.strings && config.strings.invalidImdb) || 'Enter a valid IMDb title ID.');
            if (input) {
                input.focus();
            }
            return;
        }

        if (result) {
            result.hidden = true;
        }
        if (importButton) {
            importButton.hidden = false;
        }
        setAlert(launcher, '');
        setStatus(launcher, (config.strings && config.strings.lookupBusy) || 'Looking up the film...');
        setBusy(launcher, true);
        launcher.setAttribute('data-state', 'lookup');
        var lookupGeneration = beginRequest(launcher);

        post('lookup', payloadFor(launcher, imdbId)).then(function (response) {
            if (!requestIsCurrent(launcher, lookupGeneration)) {
                return;
            }
            setBusy(launcher, false);
            setStatus(launcher, (config.strings && config.strings.lookupReady) || 'Film found.');
            showCandidate(launcher, response.candidate || {});
        }).catch(function (error) {
            if (!requestIsCurrent(launcher, lookupGeneration)) {
                return;
            }
            setBusy(launcher, false);
            setStatus(launcher, '');
            setAlert(launcher, errorMessage(error));
            if (!showRecovery(launcher, error && error.details && error.details.movie)) {
                launcher.setAttribute('data-state', 'error');
            }
            if (input && launcher.getAttribute('data-state') !== 'recovery') {
                input.focus();
            }
        });
    });

    document.addEventListener('close', function (event) {
        if (!event.target.matches('[data-lunara-movie-import-dialog]')) {
            return;
        }
        var launcher = event.target.closest('[data-lunara-movie-import-launcher]') || activeLauncher;
        var returnFocus = launcher && launcher._lunaraReturnFocus;
        if (launcher) {
            beginRequest(launcher);
            setBusy(launcher, false);
        }
        activeLauncher = null;
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }, true);

    document.addEventListener('cancel', function (event) {
        if (!event.target.matches('[data-lunara-movie-import-dialog]')) {
            return;
        }
        var launcher = event.target.closest('[data-lunara-movie-import-launcher]') || activeLauncher;
        if (launcher) {
            beginRequest(launcher);
            setBusy(launcher, false);
            launcher.setAttribute('data-state', 'local-first');
        }
    }, true);
}());
