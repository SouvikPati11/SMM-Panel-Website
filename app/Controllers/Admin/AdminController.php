<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Install\Seeder;
use App\Services\AuditService;
use App\Services\AuthService;

/** Administrator accounts and role/permission management. */
final class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $db = Database::instance();
        $admins = $db->fetchAll('SELECT a.*, GROUP_CONCAT(r.name ORDER BY r.name SEPARATOR \', \') AS roles, GROUP_CONCAT(r.id) AS role_ids FROM admins a LEFT JOIN admin_roles ar ON ar.admin_id = a.id LEFT JOIN roles r ON r.id = ar.role_id GROUP BY a.id ORDER BY a.id');
        return $this->view('admin/system/admins', ['title' => 'Administrators', 'admins' => $admins, 'roles' => $db->fetchPairs('SELECT id, name FROM roles ORDER BY name')]);
    }

    public function save(Request $request): Response
    {
        $db = Database::instance();
        $me = $this->admin();
        $id = $request->int('id');
        $data = Validator::check($request->post(), ['username' => 'required|username', 'email' => 'required|email', 'name' => 'max:100']);
        $email = strtolower($data['email']);
        if ($db->fetchColumn('SELECT id FROM admins WHERE (username = ? OR email = ?) AND id <> ?', [$data['username'], $email, $id])) {
            throw new ValidationException('Username or email already used by another administrator.');
        }
        $isSuper = $request->bool('is_super') ? 1 : 0;
        $status = $request->str('status') === 'disabled' ? 'disabled' : 'active';
        if ($id === (int) $me['id'] && ($status === 'disabled' || ($isSuper === 0 && (int) $me['is_super'] === 1))) {
            throw new ValidationException('You cannot disable yourself or remove your own super-admin flag.');
        }
        if ($isSuper && !(int) $me['is_super']) {
            throw new ValidationException('Only a super admin can grant super-admin rights.');
        }
        $row = ['username' => $data['username'], 'email' => $email, 'name' => $data['name'] ?: null, 'status' => $status, 'is_super' => $isSuper, 'updated_at' => now()];
        $pw = (string) $request->input('password', '');
        if ($pw !== '') {
            Validator::check(['password' => $pw], ['password' => 'required|min:10|max:128']);
            AuthService::assertStrongPassword($pw);
            $row['password_hash'] = password_hash($pw, PASSWORD_DEFAULT);
        } elseif (!$id) {
            throw new ValidationException('Set an initial password (min 10 characters).');
        }
        $roleIds = array_map('intval', (array) ($request->post()['roles'] ?? []));
        $db->transaction(function (Database $db) use (&$id, $row, $roleIds, $status): void {
            if ($id) {
                $db->update('admins', $row, ['id' => $id]);
                if ($status === 'disabled' || isset($row['password_hash'])) {
                    $db->query('UPDATE admins SET session_version = session_version + 1 WHERE id = ?', [$id]);
                }
            } else {
                $id = $db->insert('admins', $row + ['created_at' => now()]);
            }
            $db->query('DELETE FROM admin_roles WHERE admin_id = ?', [$id]);
            foreach ($roleIds as $rid) {
                $db->query('INSERT IGNORE INTO admin_roles (admin_id, role_id) SELECT ?, id FROM roles WHERE id = ?', [$id, $rid]);
            }
        });
        AuditService::log('admin.save', 'admin', $id, ['username' => $row['username'], 'status' => $status, 'super' => $isSuper, 'roles' => $roleIds, 'password_changed' => $pw !== '']);
        $this->success('Administrator saved.');
        return Response::redirect(admin_url('admins'));
    }

    public function delete(Request $request, int $id): Response
    {
        if ($id === (int) $this->admin()['id']) {
            throw new ValidationException('You cannot delete your own account.');
        }
        $db = Database::instance();
        $target = $db->fetch('SELECT * FROM admins WHERE id = ?', [$id]);
        if ($target && (int) $target['is_super'] === 1 && (int) $db->fetchColumn("SELECT COUNT(*) FROM admins WHERE is_super = 1 AND status = 'active'") <= 1) {
            throw new ValidationException('Cannot delete the last active super admin.');
        }
        $db->delete('admins', ['id' => $id]);
        AuditService::log('admin.delete', 'admin', $id, ['username' => $target['username'] ?? null]);
        $this->success('Administrator deleted.');
        return Response::redirect(admin_url('admins'));
    }

    public function roles(Request $request): Response
    {
        $db = Database::instance();
        $roles = $db->fetchAll('SELECT r.*, (SELECT COUNT(*) FROM admin_roles ar WHERE ar.role_id = r.id) AS admins FROM roles r ORDER BY r.name');
        $rp = [];
        foreach ($db->fetchAll('SELECT rp.role_id, p.name FROM role_permissions rp JOIN permissions p ON p.id = rp.permission_id') as $r) {
            $rp[$r['role_id']][] = $r['name'];
        }
        $perms = [];
        foreach (Seeder::PERMISSIONS as $name => [$grp, $label]) {
            $perms[$grp][$name] = $label;
        }
        return $this->view('admin/system/roles', ['title' => 'Roles & permissions', 'roles' => $roles, 'rolePerms' => $rp, 'perms' => $perms, 'edit' => $request->int('edit')]);
    }

    public function saveRole(Request $request): Response
    {
        $db = Database::instance();
        $data = Validator::check($request->post(), ['name' => 'required|max:60', 'description' => 'max:255']);
        $id = $request->int('id');
        if ($db->fetchColumn('SELECT id FROM roles WHERE name = ? AND id <> ?', [$data['name'], $id])) {
            throw new ValidationException('A role with that name exists.');
        }
        $perms = array_values(array_intersect((array) ($request->post()['permissions'] ?? []), array_keys(Seeder::PERMISSIONS)));
        $db->transaction(function (Database $db) use (&$id, $data, $perms): void {
            if ($id) {
                $db->update('roles', ['name' => $data['name'], 'description' => $data['description'] ?: null], ['id' => $id]);
            } else {
                $id = $db->insert('roles', ['name' => $data['name'], 'description' => $data['description'] ?: null, 'created_at' => now()]);
            }
            $db->query('DELETE FROM role_permissions WHERE role_id = ?', [$id]);
            foreach ($perms as $p) {
                $db->query('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$id, $p]);
            }
        });
        AuditService::log('role.save', 'role', $id, ['permissions' => $perms]);
        $this->success('Role saved.');
        return Response::redirect(admin_url('roles'));
    }

    public function deleteRole(Request $request, int $id): Response
    {
        Database::instance()->delete('roles', ['id' => $id]);
        AuditService::log('role.delete', 'role', $id);
        $this->success('Role deleted.');
        return Response::redirect(admin_url('roles'));
    }
}
