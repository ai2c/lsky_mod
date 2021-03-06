<?php
/**
 * User: Wisp X
 * Date: 2018/9/26
 * Time: 19:37
 * Link: https://github.com/wisp-x
 */

namespace app\index\controller;

use app\common\model\Folders;
use app\common\model\Images;
use think\Db;
use think\facade\Config;
use think\facade\Session;
use think\Exception;

class User extends Base
{
    public function images($keyword = '', $folderId = 0, $limit = 60)
    {
        if ($this->request->isPost()) {
            try {
                $model = $this->user->images()->order('create_time', 'desc');
                $folders = $this->user->folders()->where('parent_id', $folderId)->select();
                if (!empty($keyword)) {
                    $model = $model->where('pathname|sha1|md5|ip', 'like', "%{$keyword}%");
                }
                if (is_numeric($folderId)) {
                    $model = $model->where('folder_id', $folderId);
                }
                $images = $model->paginate($limit)->each(function ($item) {
                    $item->url = $item->url;
                    // TODO 生成缩略图
                    return $item;
                });
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
            return $this->success('success', null, [
                'images' => $images,
                'folders'=> $folders
            ]);
        }
        return $this->fetch();
    }

    public function deleteImages($deleteId = null)
    {
        if ($this->request->isPost()) {
            Db::startTrans();
            try {
                $id = $deleteId ? $deleteId : $this->request->post('id');
                $deletes = []; // 需要删除的文件
                if (is_array($id)) {
                    $images = Images::all($id);
                    foreach ($images as &$value) {
                        $deletes[$value->strategy][] = $value->pathname;
                        $value->delete();
                        unset($value);
                    }
                } else {
                    $image = Images::get($id);
                    if (!$image) {
                        throw new Exception('没有找到该图片数据');
                    }
                    $deletes[$image->strategy][] = $image->pathname;
                    $image->delete();
                }
                // 是否开启软删除(开启了只删除记录，不删除文件)
                if (!$this->config['soft_delete']) {
                    $strategy = [];
                    // 实例化所有储存策略驱动
                    $strategyAll = array_keys(Config::pull('strategy'));
                    foreach ($strategyAll as $value) {
                        // 获取储存策略驱动
                        $strategy[$value] = $this->getStrategyInstance($value);
                    }

                    foreach ($deletes as $key => $val) {
                        if (1 === count($val)) {
                            if (!$strategy[$key]->delete(isset($val[0]) ? $val[0] : null)) {
                                throw new Exception('删除失败');
                            }
                        } else {
                            if (!$strategy[$key]->deletes($val)) {
                                throw new Exception('批量删除失败');
                            }
                        }
                    }
                }
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                return $deleteId ? false : $this->error($e->getMessage());
            }
            return $deleteId ? true : $this->success('删除成功');
        }
    }

    public function createFolder()
    {
        if ($this->request->isPost()) {
            try {
                $parentId = $this->request->post('parent_id');
                $name = $this->request->post('name');
                $data = [
                    'user_id' => $this->user->id,
                    'parent_id' => $parentId,
                    'name' => $name
                ];
                $validate = $this->validate($data, 'Folders');
                if (true !== $validate) {
                    throw new Exception($validate);
                }
                Folders::create($data);
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
            return $this->success('创建成功');
        }
    }

    public function deleteFolder()
    {
        if ($this->request->isPost()) {
            Db::startTrans();
            try {
                $id = $this->request->post('id');
                $folders = $images = [];
                $this->getDeleteFoldersAndImages($id, $folders, $images);
                $folders[] = (int) $id;
                $folders && Folders::destroy($folders, true);
                $images && $this->deleteImages($images);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                return $this->error($e->getMessage());
            }
            return $this->success('删除成功');
        }
    }

    public function getFolders($parentId = 0)
    {
        if ($this->request->isPost()) {
            $folders = $this->user->folders()->where('parent_id', $parentId)->select();
            return $this->success('success', null, $folders);
        }
    }

    public function moveImages($ids = [], $folderId)
    {
        if ($this->request->isPost()) {
            if ($this->user->folders()->where('id', $folderId)->count()) {
                if (Images::where('id', 'in', $ids)->setField('folder_id', $folderId)) {
                    return $this->success('移动成功');
                }
                return $this->error('移动失败');
            } else {
                return $this->error('该文件夹不存在！');
            }
        }
    }

    public function renameFolder()
    {
        if ($this->request->isPost()) {
            Db::startTrans();
            try {
                $id = $this->request->post('id');
                $parentId = $this->request->post('parent_id');
                $name = $this->request->post('name');
                $data = [
                    'id' => $id,
                    'parent_id' => $parentId,
                    'user_id' => $this->user->id,
                    'name' => $name
                ];
                $validate = $this->validate($data, 'Folders');
                if (true !== $validate) {
                    throw new Exception($validate);
                }
                Folders::update($data);
                Db::commit();
            } catch (Exception $e) {
                Db::rollback();
                return $this->error($e->getMessage());
            }
            return $this->success('重命名成功');
        }
    }

    private function getDeleteFoldersAndImages($folderId, &$folders, &$images)
    {
        $folderList = Folders::where('parent_id', $folderId)->column('id');
        $imagesList = Images::where('folder_id', $folderId)->column('id');
        if ($imagesList) {
            $images = array_merge($images, $imagesList);
        }
        foreach ($folderList as &$value) {
            $folders[] = $value;
            $this->getDeleteFoldersAndImages($value, $folders, $images);
        }
    }

    public function settings()
    {
        if ($this->request->isPost()) {
            try {
                $data = $this->request->post();
                $validate = $this->validate($data, 'Users.edit');
                if (true !== $validate) {
                    throw new Exception($validate);
                }
                if ($data['password_old']) {
                    if (md5($data['password_old']) != $this->user->password) {
                        throw new Exception('原密码不正确');
                    }
                }
                if (!$data['password']) unset($data['password']);
                $this->user->save($data);
            } catch (Exception $e) {
                return $this->error($e->getMessage());
            }
            return $this->success('保存成功');
        }
        return $this->fetch();
    }

    public function logout()
    {
        Session::delete('uid');
        $this->redirect(url('/'));
    }
}
