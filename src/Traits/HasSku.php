<?php

namespace Gtd\Sku\Traits;

use Gtd\Sku\Contracts\AttrContract;
use Gtd\Sku\Contracts\Option;
use Gtd\Sku\Contracts\OptionContract;
use Gtd\Sku\Contracts\Sku;
use Gtd\Sku\Contracts\SkuContract;
use Gtd\Sku\SkuGenerate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Translation\Exception\NotFoundResourceException;

trait HasSku
{
    // sku关联
    public function skus(): MorphMany
    {
        // sku列表，及每条sku对应属性键值列表，价格，库存
        return $this->morphMany(config('sku.models.sku'), config('sku.morph_name'));
    }

    // 属性键值对关联
    public function attrs(): MorphMany
    {
        return $this->morphMany(config('sku.models.attr'), config('sku.morph_name'));
    }

    /**
     * 批量添加属性值
     *
     * @param Option $option
     * @param mixed ...$values
     * @return iterable
     */
    public function addAttrValues($option, ...$values)
    {
        $option = $this->getStoredOption($option);

        $values = collect($values)->flatten()->map(function ($value) use ($option) {
            $attribute_class = config('sku.models.attr');
            $option_id = $option->getKey();
            return new $attribute_class(compact('option_id', 'value'));
        });

        if ($values->isEmpty()) {
            throw new \InvalidArgumentException('Values cannot be empty');
        }

        return $this->attrs()->saveMany($values);
    }

    /**
     * 查询option实例
     *
     * @param $option
     * @return mixed
     */
    protected function getStoredOption($option): OptionContract
    {
        $optionModel = config('sku.models.option');

        if (is_numeric($option)) {
            $option = $optionModel::findOrFail($option);
        }

        if (is_string($option)) {
            $option = $optionModel::firstOrCreate(['name' => $option]);
        }

        if (! ($option instanceof OptionContract)) {
            throw (new ModelNotFoundException)->setModel($optionModel);
        }

        return $option;
    }

    /**
     * 同步属性值
     *
     * @param Option $option
     * @param mixed ...$values
     * @return iterable
     * @throws \Exception
     */
    public function syncAttrValues($option, ...$values)
    {
        DB::transaction(function () use (&$res, $option, $values) {
            $option = $this->getStoredOption($option);

            $this->removeAttrValues($option);

            $res = $this->addAttrValues($option, ...$values);
        });

        return $res;
    }

    /**
     * 移除属性及所有值
     *
     * @param $option
     * @return mixed
     */
    public function removeAttrValues($option)
    {
        $option = $this->getStoredOption($option);

        return $this->attrs()->where($option->getKeyName(), $option->getKey())->delete();
    }

    /**
     * 更新sku列表及载荷
     * TODO 可用性及参数优化
     * @param \Closure $closure
     */
    public function syncSku($closure)
    {
        // sku列表
        $list = [];

        // 执行闭包，获取新sku列表
        $closure(function ($position, $payload) use(&$list) {
            // TODO 定位数据库存在验证如何确定
            if ($position instanceof AttrContract) {
                $position = $position->getKey();
            }

            $list[] = compact('position', 'payload');
        });

        dd($list, 233);

        DB::transaction(function () use ($list) {
            // 清空sku记录
            $this->skus()->delete();

            // 插入sku记录
            foreach ($list as $value) {
                $sku = $this->skus()->create($value['payload']);
                $sku->attrs()->attach($value['position']);
            }
        });
    }

    /**
     * sku 矩阵
     */
    public function skuMatrix()
    {
        $res = [
            'option_id1' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
            'option_id2' => [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ]
        ];

        $payload = [
            'position' => [1, 1],
            'payload' => ['amount', 'stock']
        ];
    }

    public function syncSkus(SkuGenerate ...$skus)
    {
//        dd($skus);
//
        // position 可能直接是属性键值id
        // TODO 最终需要属性键值id数组来attach sku
        $positions = array_map(function (SkuGenerate $sku) {
            return collect($sku->getPosition())->map(function ($value, $option_id) {
                return compact('option_id', 'value');
            });
        }, $skus);

        dd($positions);

        // 验证定位属性键值存在于商品
        $res = collect($skus)->map(function (SkuGenerate $sku) {
            return collect($sku->getPosition())->map(function ($value, $option_id) {
                return compact('option_id', 'value');
            });
        })
            ->dd()
        ->flatten(1)
        ->unique()
            ->dd()
        ->groupBy('option_id')
        ->every(function (Collection $values, $option_id) {
            $values = $values->pluck('value');

            $count = $this->attrs()->whereOptionId($option_id)->whereIn('value', $values)->count();

            return $count >= $values->count();
        });

        // TODO 转换为属性键值id

        dd($res, 666);

        dd(66);
        $positions = array_map(function (SkuGenerate $sku) {
            return $sku->getPosition();
        }, $skus);

        collect($positions)->groupBy(function ($item) {
            dd($item);
        });

        dd($positions);
    }

    /**
     * 设置sku
     *
     * @param array $position 属性值id定位，无顺序要求
     * @param array $payload 载荷，sku表额外数据
     */
    public function addSku(array $position, $payload = [])
    {
        //TODO 先查找定位，如果定位存在，更新定位载荷信息
        // 如果没有查找到定位，新增sku，attach属性值，写入payload

        $sku = $this->findSkuByPosition($position);

        dd($sku);
    }

    /**
     * 通过数组值id数组定位查找sku
     *
     * @param array $position
     * @return null|SkuContract
     */
    public function findSkuByPosition(array $position)
    {
        return $this->skus()->whereHas('attrs', function (Builder $builder) use ($position) {
            $builder->whereIn('option_id', $position);
        }, '=', count($position))->first();
    }
}