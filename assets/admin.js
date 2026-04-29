jQuery(function($) {
    var nonce   = aosMsData.nonce;
    var ajaxurl = aosMsData.ajaxurl;

    function setStatus($el, msg, type) {
        $el.text(msg).removeClass('success error loading').addClass(type || '');
    }

    /* ── Settings ─────────────────────────────────────────────────── */
    $('#aos-ms-settings-form').on('submit', function(e) {
        e.preventDefault();
        var $status = $('#aos-ms-settings-status');
        setStatus($status, 'Saving…', 'loading');
        var data = $(this).serializeArray().reduce(function(obj, f) {
            obj[f.name] = f.value; return obj;
        }, {});
        data.action = 'aos_ms_save_settings';
        data.nonce  = nonce;
        $.post(ajaxurl, data, function(res) {
            if (res.success) setStatus($status, '✓ ' + res.data, 'success');
            else             setStatus($status, '✗ ' + res.data, 'error');
        }).fail(function() { setStatus($status, '✗ Request failed', 'error'); });
    });

    $('#aos-ms-test-civicrm').on('click', function() {
        var $status = $('#aos-ms-settings-status');
        setStatus($status, 'Testing…', 'loading');
        $.post(ajaxurl, { action: 'aos_ms_test_civicrm', nonce: nonce }, function(res) {
            if (res.success) setStatus($status, '✓ ' + res.data, 'success');
            else             setStatus($status, '✗ ' + res.data, 'error');
        }).fail(function() { setStatus($status, '✗ Request failed', 'error'); });
    });

    /* ── Sync Expired ─────────────────────────────────────────────── */
    $('#aos-ms-load-expired').on('click', function() {
        var $btn    = $(this).prop('disabled', true).text('Loading…');
        var $status = $('#aos-ms-sync-status');
        setStatus($status, 'Fetching from CiviCRM…', 'loading');
        $('#aos-ms-expired-results').hide();

        $.post(ajaxurl, { action: 'aos_ms_load_expired', nonce: nonce }, function(res) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Load Expired Members');
            if (!res.success) { setStatus($status, '✗ ' + res.data, 'error'); return; }

            var rows = res.data.rows || [];
            if (!rows.length) {
                setStatus($status, res.data.message || 'No expired members found.', 'success');
                return;
            }
            setStatus($status, '', '');
            renderExpiredTable(rows);
        }).fail(function() {
            $btn.prop('disabled', false);
            setStatus($status, '✗ Request failed', 'error');
        });
    });

    function confidenceBadge(conf) {
        var labels = { high: 'High', medium: 'Medium', low: 'Low', none: 'No Match' };
        return '<span class="confidence-' + conf + '">' + (labels[conf] || conf) + '</span>';
    }

    function statusLabel(s) {
        if (s === 'publish') return '<span class="status-publish">Published</span>';
        if (s === 'draft')   return '<span class="status-draft">Draft</span>';
        return s ? s : '—';
    }

    function listingCell(post_id, label, edit_url, status) {
        if (!post_id) return '<span style="color:#646970">—</span>';
        var link = '<a href="' + edit_url + '" target="_blank">' + escHtml(label) + '</a>';
        return link + ' ' + statusLabel(status);
    }

    function deactivateBtn(postIds, label) {
        var ids = postIds.filter(function(id) { return id > 0; });
        if (!ids.length) return '—';
        return '<button class="button aos-ms-btn-deactivate" data-post-ids="' + ids.join(',') + '">' + label + '</button>';
    }

    function renderExpiredTable(rows) {
        var tbody = $('#aos-ms-expired-tbody').empty();
        var matchedCount = 0;

        rows.forEach(function(r) {
            var provId    = r.provider_post_id || 0;
            var pracId    = r.practice_post_id || 0;
            var hasMatch  = provId > 0 || pracId > 0;
            var isMatched = hasMatch && r.confidence !== 'none';
            if (isMatched) matchedCount++;

            // Collect active (non-draft) post IDs for this row
            var activeIds = [];
            if (provId > 0 && r.provider_status !== 'draft') activeIds.push(provId);
            if (pracId > 0 && r.practice_status !== 'draft') activeIds.push(pracId);

            var actionCell;
            if (!hasMatch) {
                actionCell = '—';
            } else if (!activeIds.length) {
                actionCell = '<em>Already draft</em>';
            } else {
                actionCell = deactivateBtn(activeIds, 'Deactivate Both');
            }

            // Checkbox value: comma-separated active IDs for this row
            var checkVal  = activeIds.join(',');
            var checkDisabled = (!hasMatch || !activeIds.length) ? 'disabled' : '';

            tbody.append(
                '<tr data-provider-id="' + provId + '" data-practice-id="' + pracId + '">' +
                '<td><input type="checkbox" class="aos-ms-check-expired" ' + checkDisabled + ' value="' + checkVal + '"></td>' +
                '<td>' + escHtml(r.display_name) + '</td>' +
                '<td>' + escHtml(r.email) + '</td>' +
                '<td>' + escHtml(r.membership_type_id) + '</td>' +
                '<td>' + escHtml(r.end_date) + '</td>' +
                '<td>' + listingCell(provId, r.provider_label, r.provider_edit_url, r.provider_status) + '</td>' +
                '<td>' + listingCell(pracId, r.practice_label, r.practice_edit_url, r.practice_status) + '</td>' +
                '<td>' + confidenceBadge(r.confidence) + (r.match_method ? ' <small>(' + r.match_method + ')</small>' : '') + '</td>' +
                '<td>' + actionCell + '</td>' +
                '</tr>'
            );
        });

        $('#aos-ms-expired-count').text(rows.length + ' expired members found, ' + matchedCount + ' matched to listings');
        $('#aos-ms-deactivate-all').toggle(matchedCount > 0);
        $('#aos-ms-expired-results').show();
    }

    // Select all checkbox
    $('#aos-ms-select-all').on('change', function() {
        $('.aos-ms-check-expired:not(:disabled)').prop('checked', this.checked);
    });

    // Single row deactivate (handles both provider + practice post IDs)
    $(document).on('click', '.aos-ms-btn-deactivate', function() {
        var $btn    = $(this).prop('disabled', true).text('…');
        var postIds = $(this).data('post-ids').toString().split(',').map(Number).filter(Boolean);
        var $status = $('#aos-ms-sync-status');
        $.post(ajaxurl, { action: 'aos_ms_deactivate_listing', nonce: nonce, post_ids: postIds }, function(res) {
            if (res.success) {
                $btn.closest('tr').find('td').addClass('aos-ms-done');
                $btn.replaceWith('<em>Deactivated</em>');
            } else {
                $btn.prop('disabled', false).text('Deactivate Both');
                setStatus($status, '✗ ' + res.data, 'error');
            }
        });
    });

    // Bulk deactivate — collects all active post IDs across all checked rows
    $('#aos-ms-deactivate-all').on('click', function() {
        var ids = [];
        var $checks = $('.aos-ms-check-expired:checked');
        if (!$checks.length) $checks = $('.aos-ms-check-expired:not(:disabled)');
        $checks.each(function() {
            this.value.split(',').forEach(function(id) { var n = parseInt(id); if (n) ids.push(n); });
        });
        if (!ids.length) return;
        if (!confirm('Deactivate ' + ids.length + ' listing(s)? This sets them to Draft status.')) return;

        var $btn    = $(this).prop('disabled', true).text('Working…');
        var $status = $('#aos-ms-sync-status');
        setStatus($status, 'Deactivating…', 'loading');

        $.post(ajaxurl, { action: 'aos_ms_deactivate_bulk', nonce: nonce, post_ids: ids }, function(res) {
            $btn.prop('disabled', false).text('Deactivate All Matched Listings');
            if (res.success) {
                setStatus($status, '✓ ' + res.data.message, 'success');
                $('.aos-ms-check-expired:checked, .aos-ms-check-expired:not(:disabled)').each(function() {
                    $(this).closest('tr').find('td').addClass('aos-ms-done');
                    $(this).closest('tr').find('.aos-ms-btn-deactivate').replaceWith('<em>Deactivated</em>');
                });
            } else {
                setStatus($status, '✗ ' + res.data, 'error');
            }
        }).fail(function() { $btn.prop('disabled', false); setStatus($status, '✗ Request failed', 'error'); });
    });

    /* ── New Members ─────────────────────────────────────────────── */
    var newMembersData = [];

    $('#aos-ms-load-new').on('click', function() {
        var $btn    = $(this).prop('disabled', true).text('Loading…');
        var $status = $('#aos-ms-new-status');
        setStatus($status, 'Fetching from CiviCRM…', 'loading');
        $('#aos-ms-new-results').hide();
        newMembersData = [];

        $.post(ajaxurl, { action: 'aos_ms_load_new_members', nonce: nonce }, function(res) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-search"></span> Load New Members');
            if (!res.success) { setStatus($status, '✗ ' + res.data, 'error'); return; }
            var rows = res.data.rows || [];
            if (!rows.length) {
                setStatus($status, res.data.message || 'All active members already have listings!', 'success');
                return;
            }
            setStatus($status, '', '');
            newMembersData = rows;
            renderNewTable(rows);
        }).fail(function() {
            $btn.prop('disabled', false);
            setStatus($status, '✗ Request failed', 'error');
        });
    });

    function renderNewTable(rows) {
        var tbody = $('#aos-ms-new-tbody').empty();
        var provDir = aosMsData.providerDirectoryId;
        var pracDir = aosMsData.practiceDirectoryId;

        rows.forEach(function(r, i) {
            var loc = [r.city, r.state_province].filter(Boolean).join(', ') || '—';
            var web = r.website ? '<a href="' + escAttr(r.website) + '" target="_blank">↗</a>' : '—';

            function dirActionCell(dirType, dirId) {
                var postId  = r[dirType + '_post_id'];
                var editUrl = r[dirType + '_edit_url'];
                var status  = r[dirType + '_status'];
                if (postId) {
                    return '<a href="' + escAttr(editUrl) + '" target="_blank" class="button button-small">Edit ↗</a> ' + statusLabel(status);
                }
                return '<button class="button button-small aos-ms-btn-create" data-idx="' + i + '" data-dir="' + dirId + '" data-col="' + dirType + '">+ Create</button>';
            }

            var provBtn = dirActionCell('provider', provDir);
            var pracBtn = dirActionCell('practice', pracDir);

            var memType   = r.membership_type_name || r.membership_type_id || '—';
            var startDate = r.start_date ? r.start_date.replace(' 00:00:00', '') : '—';
            var endDate   = r.end_date   ? r.end_date.replace(' 00:00:00', '')   : '—';
            var memStatus = r.status || '—';

            tbody.append(
                '<tr id="aos-ms-new-row-' + i + '" data-idx="' + i + '">' +
                '<td><input type="checkbox" class="aos-ms-check-new" value="' + i + '"></td>' +
                '<td>' + escHtml(r.display_name) + '</td>' +
                '<td>' + escHtml(r.email) + '</td>' +
                '<td>' + escHtml(memType) + '</td>' +
                '<td>' + escHtml(startDate) + '</td>' +
                '<td>' + escHtml(endDate) + '</td>' +
                '<td>' + escHtml(memStatus) + '</td>' +
                '<td>' + (r.credentialing ? '<strong>' + escHtml(r.credentialing) + '</strong>' : '—') + '</td>' +
                '<td>' + escHtml(loc) + '</td>' +
                '<td class="aos-ms-col-web">' + web + '</td>' +
                '<td class="aos-ms-col-provider-' + i + '">' + provBtn + '</td>' +
                '<td class="aos-ms-col-practice-' + i + '">' + pracBtn + '</td>' +
                '</tr>'
            );
        });

        $('#aos-ms-new-count').text(rows.length + ' member(s) with incomplete listings');
        $('#aos-ms-create-all-drafts').show();
        $('#aos-ms-new-results').show();
    }

    $('#aos-ms-select-all-new').on('change', function() {
        $('.aos-ms-check-new').prop('checked', this.checked);
    });

    // Single draft create (provider OR practice button)
    $(document).on('click', '.aos-ms-btn-create', function() {
        var $btn    = $(this).prop('disabled', true).text('Searching web…');
        var idx     = parseInt($(this).data('idx'));
        var dirId   = parseInt($(this).data('dir'));
        var col     = $(this).data('col'); // 'provider' or 'practice'
        var c       = newMembersData[idx];
        var $status = $('#aos-ms-new-status');

        // Show progressive status so user knows the enrichment is running
        setStatus($status, '🔍 Searching for ' + escHtml(c.display_name) + '\'s practice website...', 'loading');

        // After a short moment update label to show scraping is happening
        var enrichTimer = setTimeout(function() {
            if ($btn.is(':disabled')) $btn.text('Enriching…');
            setStatus($status, '✨ Scraping website &amp; generating bio with AI…', 'loading');
        }, 3000);

        var params = $.extend({ action: 'aos_ms_create_draft', nonce: nonce, directory_id: dirId }, c);
        $.post(ajaxurl, params, function(res) {
            clearTimeout(enrichTimer);
            if (res.success) {
                var conf    = res.data.ai_conf || 'none';
                var confLbl = conf === 'high' ? '✓ high' : conf === 'low' ? '~ low' : '—';
                var badge   = '<span class="aos-ms-ai-badge' + (conf === 'low' ? ' low' : '') + '" title="AI confidence: ' + conf + '">AI ' + confLbl + '</span>';

                // Show discovered website in the row's website cell if it was empty
                var webSrc = res.data.website_source || 'none';
                var webUrl = res.data.website_url    || '';
                if (webUrl && webSrc !== 'civicrm') {
                    var $row    = $('#aos-ms-new-row-' + idx);
                    var $webCell = $row.find('td.aos-ms-col-web');
                    if ($webCell.text().trim() === '—') {
                        $webCell.html('<a href="' + escAttr(webUrl) + '" target="_blank" title="Found via web search">↗ found</a>');
                    }
                }

                $btn.replaceWith('<a href="' + res.data.edit_url + '" target="_blank" class="button button-small">Edit ↗</a> ' + badge);

                // Build status message
                var imgUrl  = res.data.image_url || '';
                var statusMsg = '✓ Draft created';
                if (webUrl) {
                    var srcLabel = webSrc === 'search' ? 'web search' : 'CiviCRM';
                    statusMsg += ' — website via ' + srcLabel + ': <a href="' + escAttr(webUrl) + '" target="_blank">' + escHtml(webUrl.replace(/^https?:\/\//, '').replace(/\/$/, '')) + '</a>';
                } else {
                    statusMsg += ' — no website found (bio is generic)';
                }
                if (imgUrl) {
                    statusMsg += ' · <a href="' + escAttr(imgUrl) + '" target="_blank">📷 image found</a>';
                } else {
                    statusMsg += ' · no image found';
                }
                setStatus($status, statusMsg, 'success');

                // Highlight row when both columns done
                var $row2 = $('#aos-ms-new-row-' + idx);
                if ($row2.find('.aos-ms-btn-create').length === 0) {
                    $row2.css('background', '#f0f7ee');
                }
            } else {
                clearTimeout(enrichTimer);
                $btn.prop('disabled', false).text('+ Create');
                setStatus($status, '✗ ' + res.data, 'error');
            }
        }).fail(function() {
            clearTimeout(enrichTimer);
            $btn.prop('disabled', false).text('+ Create');
            setStatus($status, '✗ Request failed', 'error');
        });
    });

    // Create all / selected drafts
    $('#aos-ms-create-all-drafts').on('click', function() {
        var selected = $('.aos-ms-check-new:checked').map(function() { return parseInt(this.value); }).get();
        var indices  = selected.length ? selected : newMembersData.map(function(_, i) { return i; });
        var contacts = indices.map(function(i) { return newMembersData[i]; });

        if (!contacts.length) return;
        if (!confirm('Create Provider + Practice listings for ' + contacts.length + ' member(s) with AI enrichment? This may take a minute.')) return;

        var $btn    = $(this).prop('disabled', true).text('Creating…');
        var $status = $('#aos-ms-new-status');
        setStatus($status, 'Creating drafts (this may take a minute)…', 'loading');

        $.post(ajaxurl, {
            action:   'aos_ms_create_all_drafts',
            nonce:    nonce,
            contacts: JSON.stringify(contacts),
        }, function(res) {
            $btn.prop('disabled', false).html('<span class="dashicons dashicons-edit"></span> Create All Drafts (Provider + Practice, AI Enriched)');
            if (res.success) {
                setStatus($status, '✓ ' + res.data.message, 'success');
                // Reload the table to reflect new state
                $('#aos-ms-load-new').trigger('click');
            } else {
                setStatus($status, '✗ ' + res.data, 'error');
            }
        }).fail(function() {
            $btn.prop('disabled', false);
            setStatus($status, '✗ Request failed', 'error');
        });
    });

    /* ── Helpers ─────────────────────────────────────────────────── */
    function escHtml(s) {
        return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }
    function escAttr(s) { return escHtml(s); }
});
