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

    function renderExpiredTable(rows) {
        var tbody = $('#aos-ms-expired-tbody').empty();
        var matchedCount = 0;

        rows.forEach(function(r) {
            var hasMatch  = r.listing_post_id > 0;
            var isMatched = hasMatch && r.confidence !== 'none';
            if (isMatched) matchedCount++;

            var listingCell = hasMatch
                ? '<a href="' + r.listing_edit_url + '" target="_blank">' + escHtml(r.listing_label) + '</a>'
                : '<span style="color:#646970">No match found</span>';
            var actionCell = (hasMatch && r.listing_status !== 'draft')
                ? '<button class="button aos-ms-btn-deactivate" data-post-id="' + r.listing_post_id + '">Deactivate</button>'
                : (r.listing_status === 'draft' ? '<em>Already draft</em>' : '—');

            tbody.append(
                '<tr data-post-id="' + r.listing_post_id + '">' +
                '<td><input type="checkbox" class="aos-ms-check-expired" ' + (!hasMatch || r.listing_status === 'draft' ? 'disabled' : '') + ' value="' + r.listing_post_id + '"></td>' +
                '<td>' + escHtml(r.display_name) + '</td>' +
                '<td>' + escHtml(r.email) + '</td>' +
                '<td>' + escHtml(r.membership_type_id) + '</td>' +
                '<td>' + escHtml(r.end_date) + '</td>' +
                '<td>' + listingCell + '</td>' +
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

    // Single deactivate
    $(document).on('click', '.aos-ms-btn-deactivate', function() {
        var $btn    = $(this).prop('disabled', true).text('…');
        var postId  = $(this).data('post-id');
        var $status = $('#aos-ms-sync-status');
        $.post(ajaxurl, { action: 'aos_ms_deactivate_listing', nonce: nonce, post_id: postId }, function(res) {
            if (res.success) {
                $btn.closest('tr').find('td').addClass('aos-ms-done');
                $btn.replaceWith('<em>Deactivated</em>');
            } else {
                $btn.prop('disabled', false).text('Deactivate');
                setStatus($status, '✗ ' + res.data, 'error');
            }
        });
    });

    // Bulk deactivate
    $('#aos-ms-deactivate-all').on('click', function() {
        var ids = $('.aos-ms-check-expired:checked').map(function() { return this.value; }).get();
        if (!ids.length) { ids = $('.aos-ms-check-expired:not(:disabled)').map(function() { return this.value; }).get(); }
        if (!ids.length) return;
        if (!confirm('Deactivate ' + ids.length + ' listing(s)? This sets them to Draft status.')) return;

        var $btn    = $(this).prop('disabled', true).text('Working…');
        var $status = $('#aos-ms-sync-status');
        setStatus($status, 'Deactivating…', 'loading');

        $.post(ajaxurl, { action: 'aos_ms_deactivate_bulk', nonce: nonce, post_ids: ids }, function(res) {
            $btn.prop('disabled', false).text('Deactivate All Matched Listings');
            if (res.success) {
                setStatus($status, '✓ ' + res.data.message, 'success');
                ids.forEach(function(id) {
                    $('[data-post-id="' + id + '"] td').addClass('aos-ms-done');
                    $('[data-post-id="' + id + '"] .aos-ms-btn-deactivate').replaceWith('<em>Deactivated</em>');
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

            var provBtn = '<button class="button button-small aos-ms-btn-create" data-idx="' + i + '" data-dir="' + provDir + '" data-col="provider">+ Create</button>';
            var pracBtn = '<button class="button button-small aos-ms-btn-create" data-idx="' + i + '" data-dir="' + pracDir + '" data-col="practice">+ Create</button>';

            tbody.append(
                '<tr id="aos-ms-new-row-' + i + '" data-idx="' + i + '">' +
                '<td><input type="checkbox" class="aos-ms-check-new" value="' + i + '"></td>' +
                '<td>' + escHtml(r.display_name) + '</td>' +
                '<td>' + escHtml(r.email) + '</td>' +
                '<td>' + escHtml(r.membership_type_id) + '</td>' +
                '<td>' + (r.credentialing ? '<strong>' + escHtml(r.credentialing) + '</strong>' : '—') + '</td>' +
                '<td>' + escHtml(loc) + '</td>' +
                '<td>' + web + '</td>' +
                '<td class="aos-ms-col-provider-' + i + '">' + provBtn + '</td>' +
                '<td class="aos-ms-col-practice-' + i + '">' + pracBtn + '</td>' +
                '</tr>'
            );
        });

        $('#aos-ms-new-count').text(rows.length + ' active members without a listing');
        $('#aos-ms-create-all-drafts').show();
        $('#aos-ms-new-results').show();
    }

    $('#aos-ms-select-all-new').on('change', function() {
        $('.aos-ms-check-new').prop('checked', this.checked);
    });

    // Single draft create (provider OR practice button)
    $(document).on('click', '.aos-ms-btn-create', function() {
        var $btn    = $(this).prop('disabled', true).text('Creating…');
        var idx     = parseInt($(this).data('idx'));
        var dirId   = parseInt($(this).data('dir'));
        var col     = $(this).data('col'); // 'provider' or 'practice'
        var c       = newMembersData[idx];
        var $status = $('#aos-ms-new-status');

        var params = $.extend({ action: 'aos_ms_create_draft', nonce: nonce, directory_id: dirId }, c);
        $.post(ajaxurl, params, function(res) {
            if (res.success) {
                var badge = '<span class="aos-ms-ai-badge' + (res.data.ai_conf === 'low' ? ' low' : '') + '">AI ' + (res.data.ai_conf === 'high' ? '✓' : '~') + '</span>';
                $btn.replaceWith('<a href="' + res.data.edit_url + '" target="_blank" class="button button-small">Edit ↗</a> ' + badge);
                // Highlight row only when both columns are done
                var $row = $('#aos-ms-new-row-' + idx);
                if ($row.find('.aos-ms-btn-create').length === 0) {
                    $row.css('background', '#f0f7ee');
                }
            } else {
                $btn.prop('disabled', false).text('+ Create');
                setStatus($status, '✗ ' + res.data, 'error');
            }
        }).fail(function() { $btn.prop('disabled', false).text('+ Create'); });
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
