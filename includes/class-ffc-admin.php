<?php
if (!defined('ABSPATH')) exit;

class FFCCalendarAdmin {

    public function register(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('wp_ajax_ffc_manual_sync',   [$this, 'ajax_manual_sync']);
    }

    public function add_menu(): void {
        add_options_page(
            'Calendrier FFC Normandie',
            'Calendrier FFC',
            'manage_options',
            'ffc-calendar-ligue',
            [$this, 'render_page']
        );
    }

    public function render_page(): void {
        if (!current_user_can('manage_options')) {
            return;
        }

        $last_sync   = get_option('ffc_last_sync', null);
        $event_count = FFCCalendarDB::count_events();
        $sync_log    = get_option('ffc_sync_log', []);
        $next_cron   = wp_next_scheduled('ffc_sync_cron');
        $sync_nonce  = wp_create_nonce('ffc_manual_sync');
        ?>
        <div class="wrap">
            <h1>Calendrier FFC — Ligue Normandie</h1>

            <div class="card" style="max-width:800px;padding:20px;margin-bottom:20px">
                <h2 style="margin-top:0">État de la synchronisation</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Dernière sync :</th>
                        <td><?php echo $last_sync ? esc_html($last_sync) : '<em>Jamais</em>'; ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Prochaine sync auto :</th>
                        <td>
                            <?php if ($next_cron): ?>
                                <?php echo esc_html(date_i18n('Y-m-d H:i:s', $next_cron)); ?>
                            <?php else: ?>
                                <span style="color:red"><strong>Non planifiée</strong> — désactiver puis réactiver le plugin.</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Source :</th>
                        <td>
                            <a href="https://cn.ffcorientation.fr/course/" target="_blank" rel="noopener noreferrer">
                                cn.ffcorientation.fr/course/
                            </a>
                            <span style="color:#666">(Courses CN — saisons <?php echo (int) date('Y'); ?> et <?php echo (int) date('Y') + 1; ?>)</span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Événements en base :</th>
                        <td><?php echo (int) $event_count; ?></td>
                    </tr>
                </table>

                <p style="margin-top:20px">
                    <button id="ffc-sync-btn" class="button button-primary">
                        Synchroniser maintenant
                    </button>
                    <span id="ffc-status" style="margin-left:14px;vertical-align:middle"></span>
                </p>
            </div>

            <?php if (!empty($sync_log)): ?>
            <div class="card" style="max-width:800px;padding:20px;margin-bottom:20px">
                <h2 style="margin-top:0">Historique des synchronisations</h2>
                <?php foreach ($sync_log as $entry): ?>
                    <details style="margin-bottom:8px;border:1px solid #ddd;border-radius:4px">
                        <summary style="cursor:pointer;padding:8px;font-weight:600">
                            <?php echo esc_html($entry['date']); ?>
                        </summary>
                        <ul style="margin:6px 0 6px 20px;padding:0">
                            <?php foreach ($entry['messages'] as $msg): ?>
                                <li style="font-family:monospace;font-size:12px">
                                    <?php echo esc_html($msg); ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="card" style="max-width:800px;padding:20px">
                <h2 style="margin-top:0">Utilisation du shortcode</h2>
                <p>Mois courant :</p>
                <code>[ffc_calendar_ligue]</code>
                <p style="margin-top:12px">Mois et année spécifiques :</p>
                <code>[ffc_calendar_ligue month="9" year="2026"]</code>
            </div>
        </div>

        <script>
        (function() {
            const syncBtn  = document.getElementById('ffc-sync-btn');
            const status   = document.getElementById('ffc-status');

            function post(action, nonce, callback) {
                const body = new URLSearchParams({action, nonce});
                fetch(<?php echo json_encode(admin_url('admin-ajax.php')); ?>, {
                    method : 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body   : body.toString()
                })
                .then(r => r.json())
                .then(callback)
                .catch(() => callback({success: false, data: 'Erreur réseau'}));
            }

            syncBtn.addEventListener('click', function() {
                syncBtn.disabled = true;
                syncBtn.textContent = 'Synchronisation…';
                status.textContent = '';

                post('ffc_manual_sync', <?php echo json_encode($sync_nonce); ?>, function(data) {
                    status.textContent = (data.success ? '✓ ' : '✗ ') + data.data;
                    status.style.color = data.success ? 'green' : 'red';
                    syncBtn.disabled   = false;
                    syncBtn.textContent = 'Synchroniser maintenant';
                    if (data.success) {
                        setTimeout(() => location.reload(), 1800);
                    }
                });
            });

        })();
        </script>
        <?php
    }

    public function ajax_manual_sync(): void {
        check_ajax_referer('ffc_manual_sync', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permission refusée.');
        }

        (new FFCCalendarSync())->sync();

        $count = FFCCalendarDB::count_events();
        wp_send_json_success(sprintf('Terminé — %d événements en base.', $count));
    }
}
