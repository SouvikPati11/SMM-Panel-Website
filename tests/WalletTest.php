<?php

declare(strict_types=1);

use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Money;
use App\Services\WalletService;

T::test('Money: exact decimal arithmetic without floats', function () {
    T::eq('0.300000', Money::add('0.1', '0.2'));
    T::eq('0.750000', Money::divInt(Money::mul('0.5', '1500'), 1000));
    T::eq('0.333333', Money::divInt('1', 3));
    T::eq(1, Money::cmp('10', '9.999999'));
    T::eq('25.000000', Money::percent('200', '12.5'));
});

T::test('Wallet: credit and debit write ledger rows with before/after', function () {
    $u = Fx::user('10');
    WalletService::apply((int) $u['id'], '-2.5', 'order_charge', 'w1:' . $u['id'], 'test');
    T::eq('7.500000', Fx::balance((int) $u['id']));
    $tx = Database::instance()->fetch('SELECT * FROM transactions WHERE reference = ?', ['w1:' . $u['id']]);
    T::eq('10.000000', $tx['balance_before']);
    T::eq('7.500000', $tx['balance_after']);
    T::eq('-2.500000', $tx['amount']);
});

T::test('Wallet: same reference is applied only once (idempotent)', function () {
    $u = Fx::user('5');
    $ref = 'dup:' . $u['id'];
    $a = WalletService::apply((int) $u['id'], '3', 'deposit', $ref, 'x');
    $b = WalletService::apply((int) $u['id'], '3', 'deposit', $ref, 'x');
    T::eq(false, $a['duplicate']);
    T::eq(true, $b['duplicate']);
    T::eq('8.000000', Fx::balance((int) $u['id']));
});

T::test('Wallet: cannot go negative', function () {
    $u = Fx::user('1');
    T::throws(ValidationException::class, fn () => WalletService::apply((int) $u['id'], '-1.000001', 'order_charge', null, 'x'), 'Insufficient');
    T::eq('1.000000', Fx::balance((int) $u['id']));
});

T::test('Wallet: admin adjustment requires a reason and is audited', function () {
    $u = Fx::user('0');
    T::throws(ValidationException::class, fn () => WalletService::adminAdjust((int) $u['id'], '5', '  ', 1));
    WalletService::adminAdjust((int) $u['id'], '5', 'Compensation', 1);
    T::eq('5.000000', Fx::balance((int) $u['id']));
    $tx = Database::instance()->fetch("SELECT * FROM transactions WHERE user_id = ? AND type = 'manual_adjustment'", [$u['id']]);
    T::eq(1, (int) $tx['admin_id']);
    T::true(str_contains($tx['description'], 'Compensation'));
    T::true((bool) Database::instance()->fetchColumn("SELECT id FROM audit_logs WHERE action = 'wallet.adjust' AND target_id = ?", [(string) $u['id']]));
});

T::test('Wallet: concurrent debits from two processes never overspend (row locking)', function () {
    if (!function_exists('pcntl_fork')) {
        echo "    (skipped: pcntl not available)\n";
        return;
    }
    $u = Fx::user('10');
    $uid = (int) $u['id'];
    $pids = [];
    for ($p = 0; $p < 4; $p++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            Database::setInstance(null); // fresh connection per child
            for ($i = 0; $i < 5; $i++) {
                try {
                    WalletService::apply($uid, '-1', 'order_charge', null, 'race');
                } catch (\Throwable) {
                }
            }
            exit(0);
        }
        $pids[] = $pid;
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    Database::setInstance(null);
    T::eq('0.000000', Fx::balance($uid), '20 attempted debits of 1 against 10 balance');
    T::eq(10, (int) Database::instance()->fetchColumn("SELECT COUNT(*) FROM transactions WHERE user_id = ? AND type = 'order_charge'", [$uid]));
});
