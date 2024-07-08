<?php
/*
Plugin Name: ECONOMY | FVXCENTER
Description: Ein Economy Plugin für Wordpress es wurde für das FVXCENTER Erstellt
Version: v.1.7
Author: Willi Eichhorst
*/

// Prevent direct access to the file
if (!defined('ABSPATH')) {
    exit;
}

// Create or update the database tables on plugin activation
register_activation_hook(__FILE__, 'update_account_balance_tables');

function update_account_balance_tables() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'account_balance';
    $charset_collate = $wpdb->get_charset_collate();

    $wpdb->query("ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS tax FLOAT DEFAULT 0 NOT NULL");
    $wpdb->query("ALTER TABLE $table_name ADD COLUMN IF NOT EXISTS tax_recipient_user_id BIGINT(20) UNSIGNED DEFAULT 0 NOT NULL");

    $sql_balance = "CREATE TABLE $table_name (
        user_id bigint(20) UNSIGNED NOT NULL,
        balance float DEFAULT 0 NOT NULL,
        tax float DEFAULT 0 NOT NULL,
        tax_recipient_user_id bigint(20) UNSIGNED DEFAULT 0 NOT NULL,
        PRIMARY KEY (user_id)
    ) $charset_collate;";

    $sql_tax_logs = "CREATE TABLE {$wpdb->prefix}tax_logs (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        tax_amount float NOT NULL,
        tax_date datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_balance);
    dbDelta($sql_tax_logs);
}

// Create the scheduled transfers table on plugin activation
register_activation_hook(__FILE__, 'create_scheduled_transfers_table');

function create_scheduled_transfers_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_transfers';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        recipient_user_id bigint(20) UNSIGNED NOT NULL,
        amount float NOT NULL,
        purpose varchar(255) NOT NULL,
        interval_time int NOT NULL,
        interval_unit varchar(10) NOT NULL,
        repeat_count int NOT NULL,
        next_run datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Create the scheduled deductions table on plugin activation
register_activation_hook(__FILE__, 'create_scheduled_deductions_table');

function create_scheduled_deductions_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_deductions';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id bigint(20) UNSIGNED NOT NULL,
        amount float NOT NULL,
        purpose varchar(255) NOT NULL,
        interval_time int NOT NULL,
        interval_unit varchar(10) NOT NULL,
        repeat_count int NOT NULL,
        next_run datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Create the transfer logs table on plugin activation
register_activation_hook(__FILE__, 'create_transfer_logs_table');

function create_transfer_logs_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'transfer_logs';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        sender_user_id bigint(20) UNSIGNED NOT NULL,
        recipient_user_id bigint(20) UNSIGNED NOT NULL,
        amount float NOT NULL,
        purpose varchar(255) NOT NULL,
        transfer_date datetime NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Add admin menu
add_action('admin_menu', 'account_balance_menu');

function account_balance_menu() {
    add_menu_page('Account Balance', 'Account Balance', 'manage_options', 'account-balance', 'account_balance_admin_page', 'dashicons-money-alt');
}

function account_balance_admin_page() {
    ob_start(); // Start output buffering

    global $wpdb;
    $table_name = $wpdb->prefix . 'account_balance';
    
    if (isset($_POST['update_balance'])) {
        if (!isset($_POST['account_balance_nonce']) || !wp_verify_nonce($_POST['account_balance_nonce'], 'update_balance')) {
            return;
        }
        
        $user_id = intval($_POST['user_id']);
        $new_balance = floatval($_POST['new_balance']);
        $wpdb->replace(
            $table_name,
            array(
                'user_id' => $user_id,
                'balance' => $new_balance,
            ),
            array(
                '%d',
                '%f',
            )
        );

        wp_redirect(menu_page_url('account-balance', false));
        exit;
    }

    if (isset($_POST['update_tax'])) {
        if (!isset($_POST['account_tax_nonce']) || !wp_verify_nonce($_POST['account_tax_nonce'], 'update_tax')) {
            return;
        }
        
        $user_id = intval($_POST['user_id']);
        $new_tax = floatval($_POST['new_tax']);
        $wpdb->update(
            $table_name,
            array('tax' => $new_tax),
            array('user_id' => $user_id),
            array('%f'),
            array('%d')
        );

        wp_redirect(menu_page_url('account-balance', false));
        exit;
    }

    if (isset($_POST['deduct_balance'])) {
        if (!isset($_POST['deduct_balance_nonce']) || !wp_verify_nonce($_POST['deduct_balance_nonce'], 'deduct_balance')) {
            return;
        }
        
        $user_id = intval($_POST['user_id']);
        $deduct_amount = floatval($_POST['deduct_amount']);
        $current_balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM $table_name WHERE user_id = %d", $user_id));

        if ($current_balance >= $deduct_amount) {
            $new_balance = $current_balance - $deduct_amount;
            $wpdb->update(
                $table_name,
                array('balance' => $new_balance),
                array('user_id' => $user_id),
                array('%f'),
                array('%d')
            );

            wp_redirect(menu_page_url('account-balance', false));
            exit;
        } else {
            echo '<div class="notice notice-error"><p>Insufficient balance to deduct.</p></div>';
        }
    }

    if (isset($_POST['update_tax_recipient'])) {
        if (!isset($_POST['tax_recipient_nonce']) || !wp_verify_nonce($_POST['tax_recipient_nonce'], 'update_tax_recipient')) {
            return;
        }
        
        $user_id = intval($_POST['user_id']);
        $tax_recipient_user_id = intval($_POST['tax_recipient_user_id']);
        $wpdb->update(
            $table_name,
            array('tax_recipient_user_id' => $tax_recipient_user_id),
            array('user_id' => $user_id),
            array('%d'),
            array('%d')
        );

        wp_redirect(menu_page_url('account-balance', false));
        exit;
    }

    if (isset($_POST['collect_taxes'])) {
        if (!isset($_POST['collect_taxes_nonce']) || !wp_verify_nonce($_POST['collect_taxes_nonce'], 'collect_taxes')) {
            return;
        }

        $users = $wpdb->get_results("SELECT user_id, balance, tax, tax_recipient_user_id FROM $table_name");
        foreach ($users as $user) {
            if ($user->balance >= $user->tax) {
                $new_balance = $user->balance - $user->tax;
                $wpdb->update(
                    $table_name,
                    array('balance' => $new_balance),
                    array('user_id' => $user->user_id),
                    array('%f'),
                    array('%d')
                );

                // Log the tax deduction
                $wpdb->insert(
                    $wpdb->prefix . 'tax_logs',
                    array(
                        'user_id' => $user->user_id,
                        'tax_amount' => $user->tax,
                        'tax_date' => current_time('mysql')
                    ),
                    array(
                        '%d',
                        '%f',
                        '%s'
                    )
                );

                // Transfer tax to the recipient
                if ($user->tax_recipient_user_id > 0) {
                    $recipient_balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM $table_name WHERE user_id = %d", $user->tax_recipient_user_id));
                    $new_recipient_balance = $recipient_balance + $user->tax;
                    $wpdb->update(
                        $table_name,
                        array('balance' => $new_recipient_balance),
                        array('user_id' => $user->tax_recipient_user_id),
                        array('%f'),
                        array('%d')
                    );
                }
            }
        }

        wp_redirect(menu_page_url('account-balance', false));
        exit;
    }

    echo '<div class="wrap">';
    echo '<h1>Account Balance</h1>';
    echo '<table class="wp-list-table widefat fixed striped users">';
    echo '<thead><tr><th>User ID</th><th>Username</th><th>Email</th><th>Balance</th><th>Tax</th><th>Tax Recipient User ID</th><th>Actions</th></tr></thead>';
    echo '<tbody>';

    $users = get_users();
    foreach ($users as $user) {
        $balance_data = $wpdb->get_row($wpdb->prepare("SELECT balance, tax, tax_recipient_user_id FROM $table_name WHERE user_id = %d", $user->ID));
        $balance = $balance_data ? $balance_data->balance : 0;
        $tax = $balance_data ? $balance_data->tax : 0;
        $tax_recipient_user_id = $balance_data ? $balance_data->tax_recipient_user_id : 0;

        echo '<tr>';
        echo '<td>' . esc_html($user->ID) . '</td>';
        echo '<td>' . esc_html($user->user_login) . '</td>';
        echo '<td>' . esc_html($user->user_email) . '</td>';
        echo '<td>' . esc_html($balance) . '</td>';
        echo '<td>' . esc_html($tax) . '</td>';
        echo '<td>' . esc_html($tax_recipient_user_id) . '</td>';
        echo '<td>';
        echo '<form method="post">';
        wp_nonce_field('update_balance', 'account_balance_nonce');
        echo '<input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
        echo '<input type="number" name="new_balance" step="0.01" value="' . esc_attr($balance) . '">';
        echo '<input type="submit" name="update_balance" value="Update Balance">';
        echo '</form>';
        echo '<form method="post">';
        wp_nonce_field('update_tax', 'account_tax_nonce');
        echo '<input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
        echo '<input type="number" name="new_tax" step="0.01" value="' . esc_attr($tax) . '">';
        echo '<input type="submit" name="update_tax" value="Update Tax">';
        echo '</form>';
        echo '<form method="post">';
        wp_nonce_field('deduct_balance', 'deduct_balance_nonce');
        echo '<input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
        echo '<input type="number" name="deduct_amount" step="0.01">';
        echo '<input type="submit" name="deduct_balance" value="Deduct Balance">';
        echo '</form>';
        echo '<form method="post">';
        wp_nonce_field('update_tax_recipient', 'tax_recipient_nonce');
        echo '<input type="hidden" name="user_id" value="' . esc_attr($user->ID) . '">';
        echo '<input type="number" name="tax_recipient_user_id" step="1" value="' . esc_attr($tax_recipient_user_id) . '">';
        echo '<input type="submit" name="update_tax_recipient" value="Update Tax Recipient">';
        echo '</form>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';

    echo '<form method="post" style="margin-top: 20px;">';
    wp_nonce_field('collect_taxes', 'collect_taxes_nonce');
    echo '<input type="submit" name="collect_taxes" value="Collect Taxes" class="button button-primary">';
    echo '</form>';

    echo '</div>';
    ob_end_flush(); // Flush the output buffer
}

// Shortcode to display the user's account balance and tax
add_shortcode('account_balance', 'display_account_balance');

function display_account_balance() {
    if (!is_user_logged_in()) {
        return 'Please log in to view your account balance.';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'account_balance';

    $account_data = $wpdb->get_row($wpdb->prepare("SELECT balance, tax, tax_recipient_user_id FROM $table_name WHERE user_id = %d", $user_id));
    $balance = $account_data ? $account_data->balance : 0;
    $tax = $account_data ? $account_data->tax : 0;
    $tax_recipient_user_id = $account_data ? $account_data->tax_recipient_user_id : 0;

    return '<p>Your account balance is: ' . esc_html($balance) . ' Euro</p>'
        . '<p>Your tax is: ' . esc_html($tax) . ' Euro</p>'
        . '<p>Your tax recipient user ID is: ' . esc_html($tax_recipient_user_id) . '</p>';
}

// Shortcode to display the user's tax deduction log
add_shortcode('tax_deduction_log', 'display_tax_deduction_log');

function display_tax_deduction_log() {
    if (!is_user_logged_in()) {
        return 'Please log in to view your tax deduction log.';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'tax_logs';

    $logs = $wpdb->get_results($wpdb->prepare("SELECT tax_amount, tax_date FROM $table_name WHERE user_id = %d ORDER BY tax_date DESC", $user_id));

    if (empty($logs)) {
        return '<p>No tax deductions found.</p>';
    }

    $output = '<table><thead><tr><th>Date</th><th>Tax Amount</th></tr></thead><tbody>';

    foreach ($logs as $log) {
        $output .= '<tr><td>' . esc_html($log->tax_date) . '</td><td>' . esc_html($log->tax_amount) . ' Euro</td></tr>';
    }

    $output .= '</tbody></table>';

    return $output;
}

// Shortcode to display user balance
add_shortcode('display_balance', 'display_user_balance');

function display_user_balance() {
    if (is_user_logged_in()) {
        global $wpdb;
        $user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'account_balance';
        $balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM $table_name WHERE user_id = %d", $user_id));

        if ($balance === null) {
            $balance = 0;
        }

        return 'Your account balance is ' . esc_html($balance) . ' Euro.';
    } else {
        return 'You need to log in to see your account balance.';
    }
}

// Shortcode to transfer balance to another user (first form)
add_shortcode('transfer_balance', 'transfer_balance_shortcode');

function transfer_balance_shortcode() {
    return generate_transfer_form('transfer_balance', 'Transfer 1');
}

// Shortcode to transfer balance to another user with time interval (second form)
add_shortcode('transfer_balance_with_interval', 'transfer_balance_with_interval_shortcode');

function transfer_balance_with_interval_shortcode() {
    return generate_transfer_form('transfer_balance_with_interval', 'Transfer 2');
}

function generate_transfer_form($form_name, $button_text) {
    if (is_user_logged_in()) {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'account_balance';
        
        if (isset($_POST[$form_name])) {
            if (!isset($_POST[$form_name . '_nonce']) || !wp_verify_nonce($_POST[$form_name . '_nonce'], $form_name)) {
                return;
            }

            $recipient_user_id = intval($_POST['recipient_user_id']);
            $amount = floatval($_POST['amount']);
            $purpose = sanitize_text_field($_POST['purpose']);

            // For Transfer 2: Adding time interval functionality
            if ($form_name === 'transfer_balance_with_interval') {
                $interval_time = intval($_POST['interval_time']);
                $interval_unit = sanitize_text_field($_POST['interval_unit']);
                $repeat_count = intval($_POST['repeat_count']);
                $next_run = current_time('mysql');

                for ($i = 0; $i < $repeat_count; $i++) {
                    switch ($interval_unit) {
                        case 'minutes':
                            $next_run = date('Y-m-d H:i:s', strtotime($next_run . ' + ' . $interval_time . ' minutes'));
                            break;
                        case 'hours':
                            $next_run = date('Y-m-d H:i:s', strtotime($next_run . ' + ' . $interval_time . ' hours'));
                            break;
                        case 'days':
                            $next_run = date('Y-m-d H:i:s', strtotime($next_run . ' + ' . $interval_time . ' days'));
                            break;
                    }
                    $wpdb->insert(
                        "{$wpdb->prefix}scheduled_transfers",
                        array(
                            'user_id' => $current_user_id,
                            'recipient_user_id' => $recipient_user_id,
                            'amount' => $amount,
                            'purpose' => $purpose,
                            'interval_time' => $interval_time,
                            'interval_unit' => $interval_unit,
                            'repeat_count' => $repeat_count,
                            'next_run' => $next_run,
                        ),
                        array('%d', '%d', '%f', '%s', '%d', '%s', '%d', '%s')
                    );
                }

                wp_redirect(add_query_arg($form_name . '_success', '1'));
                exit;
            } else {
                // Normal transfer logic
                $sender_balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM $table_name WHERE user_id = %d", $current_user_id));
                if ($sender_balance >= $amount && $amount > 0) {
                    $recipient_balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM $table_name WHERE user_id = %d", $recipient_user_id));

                    $wpdb->query('START TRANSACTION');

                    $wpdb->update(
                        $table_name,
                        array('balance' => $sender_balance - $amount),
                        array('user_id' => $current_user_id),
                        array('%f'),
                        array('%d')
                    );

                    if ($recipient_balance === null) {
                        $wpdb->insert(
                            $table_name,
                            array('user_id' => $recipient_user_id, 'balance' => $amount),
                            array('%d', '%f')
                        );
                    } else {
                        $wpdb->update(
                            $table_name,
                            array('balance' => $recipient_balance + $amount),
                            array('user_id' => $recipient_user_id),
                            array('%f'),
                            array('%d')
                        );
                    }

                    // Log the transfer
                    $wpdb->insert(
                        "{$wpdb->prefix}transfer_logs",
                        array(
                            'sender_user_id' => $current_user_id,
                            'recipient_user_id' => $recipient_user_id,
                            'amount' => $amount,
                            'purpose' => $purpose,
                            'transfer_date' => current_time('mysql'),
                        ),
                        array('%d', '%d', '%f', '%s', '%s')
                    );

                    $wpdb->query('COMMIT');

                    wp_redirect(add_query_arg($form_name . '_success', '1'));
                    exit;
                } else {
                    return 'Insufficient funds or invalid amount.';
                }
            }
        }

        ob_start(); // Start output buffering
        ?>
        <form method="post">
            <?php wp_nonce_field($form_name, $form_name . '_nonce'); ?>
            <p>
                <label for="recipient_user_id">Recipient User:</label>
                <?php
                $users = $wpdb->get_results("SELECT ID, user_login FROM {$wpdb->prefix}users");
                echo '<select name="recipient_user_id" id="recipient_user_id" required>';
                foreach ($users as $user) {
                    echo '<option value="' . esc_attr($user->ID) . '">' . esc_html($user->user_login) . '</option>';
                }
                echo '</select>';
                ?>
            </p>
            <p>
                <label for="amount">Amount:</label>
                <input type="number" step="0.01" name="amount" id="amount" required>
            </p>
            <p>
                <label for="purpose">Purpose:</label>
                <input type="text" name="purpose" id="purpose" required>
            </p>
            <?php if ($form_name === 'transfer_balance_with_interval'): ?>
                <p>
                    <label for="interval_time">Interval Time:</label>
                    <input type="number" name="interval_time" id="interval_time" required>
                    <select name="interval_unit" id="interval_unit" required>
                        <option value="minutes">Minutes</option>
                        <option value="hours">Hours</option>
                        <option value="days">Days</option>
                    </select>
                </p>
                <p>
                    <label for="repeat_count">Repeat Count:</label>
                    <input type="number" name="repeat_count" id="repeat_count" required>
                </p>
            <?php endif; ?>
            <p>
                <input type="submit" name="<?php echo esc_attr($form_name); ?>" value="<?php echo esc_attr($button_text); ?>" class="button button-primary">
            </p>
        </form>
        <?php
        return ob_get_clean();
    } else {
        return 'You need to log in to transfer balance.';
    }
}

// Shortcode to display transfer logs
add_shortcode('transfer_logs', 'display_transfer_logs');

function display_transfer_logs() {
    if (is_user_logged_in()) {
        global $wpdb;
        $current_user_id = get_current_user_id();
        $table_name = $wpdb->prefix . 'transfer_logs';

        $logs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name WHERE sender_user_id = %d OR recipient_user_id = %d ORDER BY transfer_date DESC",
            $current_user_id, $current_user_id
        ));

        if (empty($logs)) {
            return 'No transfer logs found.';
        }

        ob_start(); // Start output buffering
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Sender</th>
                    <th>Recipient</th>
                    <th>Amount</th>
                    <th>Purpose</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><?php echo esc_html(get_userdata($log->sender_user_id)->user_login); ?></td>
                        <td><?php echo esc_html(get_userdata($log->recipient_user_id)->user_login); ?></td>
                        <td><?php echo esc_html($log->amount); ?> Euro</td>
                        <td><?php echo esc_html($log->purpose); ?></td>
                        <td><?php echo esc_html($log->transfer_date); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
        return ob_get_clean();
    } else {
        return 'You need to log in to view transfer logs.';
    }
}

// Schedule event hook
add_action('wp', 'schedule_transfers_event');
function schedule_transfers_event() {
    if (!wp_next_scheduled('process_scheduled_transfers')) {
        wp_schedule_event(time(), 'every_minute', 'process_scheduled_transfers');
    }
}

// Process scheduled transfers
add_action('process_scheduled_transfers', 'process_scheduled_transfers_callback');
function process_scheduled_transfers_callback() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'scheduled_transfers';
    $current_time = current_time('mysql');

    $transfers = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE next_run <= %s", $current_time));

    foreach ($transfers as $transfer) {
        $sender_balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM {$wpdb->prefix}account_balance WHERE user_id = %d", $transfer->user_id));
        if ($sender_balance >= $transfer->amount) {
            $recipient_balance = $wpdb->get_var($wpdb->prepare("SELECT balance FROM {$wpdb->prefix}account_balance WHERE user_id = %d", $transfer->recipient_user_id));

            $wpdb->query('START TRANSACTION');

            $wpdb->update(
                "{$wpdb->prefix}account_balance",
                array('balance' => $sender_balance - $transfer->amount),
                array('user_id' => $transfer->user_id),
                array('%f'),
                array('%d')
            );

            if ($recipient_balance === null) {
                $wpdb->insert(
                    "{$wpdb->prefix}account_balance",
                    array('user_id' => $transfer->recipient_user_id, 'balance' => $transfer->amount),
                    array('%d', '%f')
                );
            } else {
                $wpdb->update(
                    "{$wpdb->prefix}account_balance",
                    array('balance' => $recipient_balance + $transfer->amount),
                    array('user_id' => $transfer->recipient_user_id),
                    array('%f'),
                    array('%d')
                );
            }

            // Log the Transfer
            $wpdb->insert(
                "{$wpdb->prefix}transfer_logs",
                array(
                    'sender_user_id' => $transfer->user_id,
                    'recipient_user_id' => $transfer->recipient_user_id,
                    'amount' => $transfer->amount,
                    'purpose' => $transfer->purpose,
                    'transfer_date' => current_time('mysql'),
                ),
                array('%d', '%d', '%f', '%s', '%s')
            );

            $wpdb->delete(
                $table_name,
                array('id' => $transfer->id),
                array('%d')
            );

            $wpdb->query('COMMIT');
        } else {
            // Optional: handle insufficient funds scenario
        }
    }
}

// Add custom interval for cron job
add_filter('cron_schedules', 'add_custom_cron_intervals');
function add_custom_cron_intervals($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => __('Every Minute'),
    );
    return $schedules;
}
?>
