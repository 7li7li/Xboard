# Xboard 管理员账号操作 API

本文档依据当前仓库源码整理，面向需要从第三方服务端管理 Xboard 用户账号的调用方。

> 这些接口原本服务于 Xboard 管理后台，不是独立、稳定的开放 API。管理员 Token 拥有完整后台权限，调用方应仅在可信服务端保存和使用，禁止下发到浏览器、客户端或第三方不受控系统。

## 1. 基本约定

### 1.1 地址

```text
站点地址: https://your-xboard.example.com
管理员 API: https://your-xboard.example.com/api/v2/{secure_path}
登录 API: https://your-xboard.example.com/api/v1/passport/auth/login
```

`secure_path` 是当前站点配置的后台安全路径。它也是管理员后台 URL 中使用的路径，不是固定的 `admin`。

### 1.2 请求头

除登录接口外，本文档中的接口均要求管理员 Bearer Token：

```http
Authorization: Bearer <ADMIN_TOKEN>
Accept: application/json
Content-Type: application/json
```

Token 对应的用户必须满足 `is_admin = true`，否则返回 HTTP `403`：

```json
{
  "message": "Unauthorized"
}
```

### 1.3 数据单位

| 数据 | 单位与说明 |
| --- | --- |
| `balance`、`commission_balance` | `/user/update` 的请求和用户列表响应使用主货币单位，例如 `100.50`；数据库内部使用最小货币单位并乘以 100 |
| 订单 `total_amount` | 内部最小货币单位，通常为分，例如 100 元传 `10000` |
| `transfer_enable`、`u`、`d` | 字节 |
| `transfer_enable_gb`、`u_gb`、`d_gb` | GiB，服务端按 `1024^3` 换算为字节 |
| `expired_at`、`started_at`、`next_reset_at`、`last_reset_at` | Unix 秒级时间戳；部分字段可传 `null` 表示长期有效或不设置 |
| `speed_limit` | 按当前模型约定为 Mbps |

### 1.4 常规响应

大部分接口使用以下结构：

```json
{
  "status": "success",
  "message": "Success",
  "data": true,
  "error": null
}
```

失败示例：

```json
{
  "status": "fail",
  "message": "User does not exist",
  "data": null,
  "error": null
}
```

注意：用户列表、批量创建、CSV 下载及流量重置接口使用不同的响应结构，调用方不能假设所有响应都包含 `status`。

## 2. 管理员登录

### `POST /api/v1/passport/auth/login`

使用管理员邮箱和密码取得 Sanctum Token。

请求：

```json
{
  "email": "admin@example.com",
  "password": "your-password"
}
```

字段：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `email` | 是 | 管理员邮箱 |
| `password` | 是 | 最少 8 位 |

成功响应：

```json
{
  "status": "success",
  "message": "Success",
  "data": {
    "token": "用户订阅令牌",
    "auth_data": "Bearer eyJ...",
    "is_admin": true
  },
  "error": null
}
```

后续请求的 `Authorization` 头直接使用 `data.auth_data`。登录产生的 Token 当前拥有 `*` 权限，有效期一年。

## 3. 接口总览

| 方法 | 路径 | 用途 |
| --- | --- | --- |
| `GET`/`POST` | `/user/fetch` | 分页查询用户 |
| `GET` | `/user/getUserInfoById` | 按 ID 查询用户详情 |
| `POST` | `/user/generate` | 单个或批量创建账号 |
| `POST` | `/user/update` | 修改账号、余额、密码、权限等 |
| `POST` | `/user/subscription/fetch` | 按用户查询订阅 |
| `POST` | `/user/subscription/save` | 创建或修改订阅 |
| `POST` | `/user/subscription/drop` | 删除订阅 |
| `POST` | `/user/resetSecret` | 重置订阅令牌和 UUID |
| `POST` | `/user/ban` | 批量封禁用户 |
| `POST` | `/user/sendMail` | 给选中、筛选或全部用户发邮件 |
| `POST` | `/user/dumpCSV` | 导出用户 CSV |
| `POST` | `/user/destroy` | 永久删除用户及主要关联数据 |
| `POST` | `/order/assign` | 管理员代用户创建订阅订单 |
| `POST` | `/order/paid` | 将待支付订单标记为已支付并开通订阅 |
| `POST` | `/order/cancel` | 取消待支付订单 |
| `GET`/`POST` | `/order/fetch` | 查询订单列表与状态 |
| `POST` | `/order/detail` | 按订单数据库 ID 查询详情 |
| `POST` | `/traffic-reset/reset-user` | 手动重置用户流量 |

上述路径均相对于：

```text
/api/v2/{secure_path}
```

## 4. 用户查询

### 4.1 分页查询用户

#### `GET|POST /api/v2/{secure_path}/user/fetch`

参数：

| 字段 | 必填 | 默认值 | 说明 |
| --- | --- | --- | --- |
| `current` | 否 | `1` | 页码，最小为 1 |
| `pageSize` | 否 | `10` | 每页数量，范围 1 至 100 |
| `filter` | 否 | - | 筛选数组 |
| `sort` | 否 | - | 排序数组；最终仍会追加 `id DESC` |

筛选项的实际结构：

```json
{
  "id": "email",
  "value": "user@example.com",
  "logic": "and"
}
```

- `id`：字段名，例如 `id`、`email`、`banned`、`plan_id`、`balance`、`total_used`。
- `value`：普通值使用模糊匹配；数组使用 `IN`；也可使用 `eq:value`、`gt:value`、`gte:value`、`lt:value`、`lte:value`、`like:value`、`notlike:value` 等形式。
- `logic`：`and` 或 `or`，默认 `and`。

示例：

```json
{
  "current": 1,
  "pageSize": 20,
  "filter": [
    {
      "id": "email",
      "value": "eq:user@example.com",
      "logic": "and"
    }
  ],
  "sort": [
    {
      "id": "created_at",
      "desc": true
    }
  ]
}
```

响应不是常规 `status` 包装：

```json
{
  "total": 1,
  "current_page": 1,
  "per_page": 20,
  "last_page": 1,
  "data": [
    {
      "id": 123,
      "email": "user@example.com",
      "balance": 100.5,
      "commission_balance": 0,
      "banned": false,
      "plan": {
        "id": 1,
        "name": "Standard"
      },
      "subscribe_url": "https://your-xboard.example.com/s/..."
    }
  ]
}
```

列表响应中的 `balance` 和 `commission_balance` 已除以 100，订阅明细不包含在列表响应中。

### 4.2 查询用户详情

#### `GET /api/v2/{secure_path}/user/getUserInfoById?id={USER_ID}`

参数：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `id` | 是 | 用户 ID，数值 |

该接口会加载邀请人和订阅数据，并可能把旧版用户订阅数据回填至订阅表。

> 单位差异：该接口直接返回用户模型，`balance` 和 `commission_balance` 是数据库内部最小货币单位；这与 `/user/fetch` 和 `/user/subscription/fetch` 返回的主货币单位不同。第三方集成更适合使用 `/user/subscription/fetch` 获取规范化数据。

### 4.3 按用户查询订阅

#### `POST /api/v2/{secure_path}/user/subscription/fetch`

请求二选一：

```json
{
  "user_id": 123
}
```

或：

```json
{
  "email": "user@example.com"
}
```

如果同时提供 `user_id` 和 `email`，优先使用 `user_id`。响应包含规范化用户信息和 `subscriptions` 数组，余额为主货币单位。

订阅对象示例：

```json
{
  "id": 88,
  "plan_id": 1,
  "order_id": 9001,
  "status": 1,
  "started_at": 1785283200,
  "expired_at": 1787961600,
  "transfer_enable": 107374182400,
  "u": 0,
  "d": 0,
  "total_used": 0,
  "remaining": 107374182400,
  "group_id": 1,
  "speed_limit": 100,
  "device_limit": 5,
  "next_reset_at": 1787961600,
  "last_reset_at": null,
  "reset_count": 0
}
```

订阅状态：

| 值 | 含义 |
| --- | --- |
| `1` | active，启用 |
| `2` | cancelled，已取消 |
| `3` | expired，已过期 |

## 5. 创建账号

### `POST /api/v2/{secure_path}/user/generate`

管理员创建不经过公开注册流程，因此即使站点关闭注册也可调用，并会绕过验证码、邮箱验证、邀请码、邮箱白名单、IP 注册限制和公开注册插件钩子。

### 5.1 创建单个账号

请求：

```json
{
  "email_prefix": "newuser",
  "email_suffix": "example.com",
  "password": "StrongPassword123",
  "plan_id": 1,
  "expired_at": 1787961600
}
```

创建的邮箱为 `newuser@example.com`。

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `email_prefix` | 单个创建必填 | `@` 前的部分 |
| `email_suffix` | 是 | `@` 后的域名，不含 `@` |
| `password` | 否 | 不传时默认使用完整邮箱作为密码，生产环境不建议省略 |
| `plan_id` | 否 | 初始订阅计划 ID；不传时可能按站点配置分配试用计划 |
| `expired_at` | 否 | 初始订阅到期时间，Unix 秒级时间戳 |

成功响应只返回 `true`，不返回用户 ID 或 Bearer Token：

```json
{
  "status": "success",
  "message": "Success",
  "data": true,
  "error": null
}
```

创建后可使用 `/user/subscription/fetch` 按邮箱查询用户，或使用新账号调用登录接口取得用户 Token。

> `plan_id` 当前只校验为整数，不校验计划是否存在。调用前应通过 `GET /api/v2/{secure_path}/plan/fetch` 获取有效计划 ID。

### 5.2 批量创建账号

最多一次请求 500 个：

```json
{
  "generate_count": 3,
  "email_prefix": "customer",
  "email_suffix": "example.com",
  "password": "SharedPassword123",
  "plan_id": 1,
  "expired_at": 1787961600,
  "download_csv": false
}
```

指定 `email_prefix` 时生成：

```text
customer_1@example.com
customer_2@example.com
customer_3@example.com
```

不指定 `email_prefix` 时，系统随机生成 6 位邮箱前缀。

批量创建的 JSON 响应是非标准结构：

```json
{
  "code": 0,
  "message": "批量生成成功",
  "data": [
    {
      "email": "customer_1@example.com",
      "password": "SharedPassword123",
      "expired_at": "2026-08-29 00:00:00",
      "uuid": "...",
      "created_at": "2026-07-29 12:00:00",
      "subscribe_url": "https://your-xboard.example.com/s/..."
    }
  ]
}
```

`download_csv=true` 时返回 CSV 文件而不是 JSON。

## 6. 修改用户

### `POST /api/v2/{secure_path}/user/update`

请求只需提供 `id` 和需要修改的字段：

```json
{
  "id": 123,
  "email": "new-address@example.com",
  "password": "NewPassword123",
  "balance": 100.5,
  "banned": false,
  "remarks": "第三方渠道用户",
  "invite_user_email": "inviter@example.com"
}
```

支持字段：

| 字段 | 类型 | 说明 |
| --- | --- | --- |
| `id` | integer | 必填，用户 ID |
| `email` | email | 新邮箱，不能与其他用户重复 |
| `password` | string | 新密码，最少 8 位 |
| `balance` | numeric | 设置最终余额，主货币单位；不是增加金额 |
| `commission_balance` | numeric | 设置最终佣金余额，主货币单位 |
| `banned` | boolean | `true` 封禁，`false` 解封 |
| `is_admin` | boolean | 是否管理员，修改此字段风险很高 |
| `is_staff` | boolean | 是否员工 |
| `commission_rate` | integer | 佣金比例，0 至 100 |
| `commission_type` | integer | 佣金类型 |
| `discount` | integer | 专属折扣，0 至 100 |
| `remarks` | string/null | 备注 |
| `plan_id` | integer/null | 旧版聚合订阅计划字段 |
| `transfer_enable` | numeric | 旧版聚合总流量，字节 |
| `u` | integer | 旧版聚合上行流量，字节 |
| `d` | integer | 旧版聚合下行流量，字节 |
| `expired_at` | integer/null | 旧版聚合到期时间 |
| `speed_limit` | integer/null | 旧版聚合限速 |
| `device_limit` | integer/null | 旧版聚合设备限制 |
| `invite_user_email` | email | 邀请人的现有邮箱；该字段在控制器中额外处理 |

成功响应：

```json
{
  "status": "success",
  "message": "Success",
  "data": true,
  "error": null
}
```

### 6.1 余额操作的重要限制

`balance` 是覆盖值：

```json
{
  "id": 123,
  "balance": 150
}
```

表示把余额设置为 150，而不是在原余额上增加 150。当前没有管理员“原子增加余额”的 HTTP API。第三方先查询再覆盖会产生并发丢失更新风险，不适合充值、退款等财务场景。

### 6.2 修改接口的重要副作用

1. 请求没有携带有效的 `invite_user_email` 时，控制器会把 `invite_user_id` 清空。需要保留邀请关系时，必须同时提交当前邀请人的邮箱。
2. 当用户有多个订阅时，`plan_id`、`transfer_enable`、`u`、`d`、`expired_at`、`speed_limit`、`device_limit` 等旧版聚合订阅字段会被忽略。应改用 `/user/subscription/save` 修改具体订阅。
3. 设置 `banned=true` 会删除该用户所有 Sanctum 登录会话；设置新密码本身不会删除旧 Bearer Token。
4. 设置 `is_admin` 可直接改变后台权限，不应向普通第三方业务逻辑开放。

## 7. 订阅操作

### 7.1 创建或修改订阅

#### `POST /api/v2/{secure_path}/user/subscription/save`

不传 `id` 时创建订阅，传 `id` 时修改该用户已有订阅。

创建示例：

```json
{
  "user_id": 123,
  "plan_id": 1,
  "status": 1,
  "started_at": 1785283200,
  "expired_at": 1787961600,
  "transfer_enable_gb": 100,
  "u_gb": 0,
  "d_gb": 0,
  "speed_limit": 100,
  "device_limit": 5
}
```

修改示例：

```json
{
  "id": 88,
  "user_id": 123,
  "plan_id": 1,
  "expired_at": 1790553600,
  "transfer_enable_gb": 200
}
```

字段：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `user_id` | 是 | 必须存在的用户 ID |
| `id` | 修改时必填 | 订阅 ID；省略则新建 |
| `plan_id` | 是 | 必须存在的计划 ID |
| `status` | 否 | `1` 启用、`2` 取消、`3` 过期；新建默认 `1` |
| `started_at` | 否 | Unix 秒级时间戳；新建默认当前时间 |
| `expired_at` | 否 | Unix 秒级时间戳或 `null` |
| `transfer_enable` | 否 | 总流量，字节 |
| `transfer_enable_gb` | 否 | 总流量，GiB；提供时优先于 `transfer_enable` |
| `u` / `u_gb` | 否 | 已用上行流量，字节或 GiB |
| `d` / `d_gb` | 否 | 已用下行流量，字节或 GiB |
| `group_id` | 否 | 节点组 ID；默认使用计划的节点组 |
| `speed_limit` | 否 | 限速；默认使用计划值 |
| `device_limit` | 否 | 设备限制；默认使用计划值 |
| `next_reset_at` | 否 | 下次重置时间；省略时按计划自动计算 |
| `last_reset_at` | 否 | 上次重置时间 |
| `reset_count` | 否 | 重置次数，最小 0 |

保存后服务端会同步用户聚合字段并通知节点。响应 `data` 为包含全部订阅的规范化用户对象。

> 该接口直接分配权益，不创建订单、不校验实际收款，也不产生正常订单记录。需要保留购买、佣金和订单链路时，应使用“管理员代用户创建订单”流程。

### 7.2 删除订阅

#### `POST /api/v2/{secure_path}/user/subscription/drop`

```json
{
  "id": 88,
  "user_id": 123
}
```

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `id` | 是 | 订阅 ID |
| `user_id` | 否 | 建议始终提供，防止误删其他用户的同 ID 订阅 |

删除后会重新同步用户聚合字段并通知节点。响应 `data` 为删除后的用户及剩余订阅。

## 8. 管理员代用户购买订阅

如果第三方操作代表一笔真实购买，推荐使用订单流程，而不是直接调用 `/user/subscription/save`。

### 8.1 创建订单

#### `POST /api/v2/{secure_path}/order/assign`

```json
{
  "email": "user@example.com",
  "plan_id": 1,
  "period": "month_price",
  "total_amount": 10000,
  "intent": "purchase"
}
```

字段：

| 字段 | 必填 | 说明 |
| --- | --- | --- |
| `email` | 是 | 已存在的用户邮箱 |
| `plan_id` | 是 | 计划 ID |
| `period` | 是 | 购买周期，见下表 |
| `total_amount` | 是 | 订单金额，内部最小货币单位，通常为分 |
| `subscription_id` | 续费/升级时使用 | 目标订阅 ID |
| `intent` | 否 | `purchase`、`renew`、`upgrade`、`reset`，默认 `purchase` |

可用周期：

```text
month_price
quarter_price
half_year_price
year_price
two_year_price
three_year_price
onetime_price
reset_price
```

成功时 `data` 是订单号：

```json
{
  "status": "success",
  "message": "Success",
  "data": "20260729...",
  "error": null
}
```

用户存在未完成订单时不能再次分配。

### 8.2 标记支付并开通

#### `POST /api/v2/{secure_path}/order/paid`

```json
{
  "trade_no": "20260729..."
}
```

仅待支付订单可以执行。成功后订单服务会执行开通/续费/升级逻辑。

### 8.3 取消待支付订单

#### `POST /api/v2/{secure_path}/order/cancel`

```json
{
  "trade_no": "20260729..."
}
```

仅待支付订单可以取消。

> `/order/assign` 与 `/order/paid` 是两个独立请求，当前没有调用方幂等键。第三方必须持久化 `trade_no`，并在重试前查询订单状态，避免重复创建订单。

### 8.4 查询订单状态

#### `GET|POST /api/v2/{secure_path}/order/fetch`

按订单号查询示例：

```json
{
  "current": 1,
  "pageSize": 10,
  "filter": [
    {
      "id": "trade_no",
      "value": "eq:20260729..."
    }
  ]
}
```

响应使用分页结构。订单主要状态为：

| 值 | 含义 |
| --- | --- |
| `0` | 待支付 |
| `1` | 开通中 |
| `2` | 已取消 |
| `3` | 已完成 |
| `4` | 已折抵 |

如已从列表响应取得订单数据库 `id`，可查询完整详情：

```http
POST /api/v2/{secure_path}/order/detail
```

```json
{
  "id": 9001
}
```

## 9. 凭据与账号状态

### 9.1 重置订阅密钥

#### `POST /api/v2/{secure_path}/user/resetSecret`

```json
{
  "id": 123
}
```

该操作会同时生成新的：

- 用户订阅令牌 `token`，旧订阅 URL 随即失效；
- 用户 `uuid`，可能影响节点侧身份识别。

该操作不会修改密码，也不会撤销用户现有 Bearer Token。

### 9.2 封禁用户

#### `POST /api/v2/{secure_path}/user/ban`

封禁指定用户：

```json
{
  "scope": "selected",
  "user_ids": [123, 124]
}
```

按筛选条件封禁：

```json
{
  "scope": "filtered",
  "filter": [
    {
      "id": "plan_id",
      "value": "eq:1"
    }
  ]
}
```

封禁全部用户：

```json
{
  "scope": "all"
}
```

> 高风险：如果省略 `scope`、`user_ids` 和有效 `filter`，服务端默认把范围解释为 `all`，会封禁全部用户。调用方必须显式传递 `scope`。批量封禁不会逐个撤销 Sanctum Token；需要立即撤销会话时，对单个用户调用 `/user/update` 并传 `banned=true`。

解封没有独立批量接口，可逐个调用：

```json
{
  "id": 123,
  "banned": false
}
```

## 10. 邮件、导出与删除

### 10.1 发送邮件

#### `POST /api/v2/{secure_path}/user/sendMail`

```json
{
  "scope": "selected",
  "user_ids": [123],
  "subject": "服务通知",
  "content": "您的服务已更新。"
}
```

`subject` 和 `content` 必填。范围支持：

| `scope` | 说明 |
| --- | --- |
| `selected` | 使用 `user_ids` |
| `filtered` | 使用与 `/user/fetch` 相同的 `filter` |
| `all` | 全部用户 |

邮件通过队列任务异步发送。接口返回成功只表示任务已入队，不表示邮件已投递。

> 高风险：未指定范围时默认 `all`。

### 10.2 导出 CSV

#### `POST /api/v2/{secure_path}/user/dumpCSV`

```json
{
  "scope": "selected",
  "user_ids": [123, 124]
}
```

响应为 `text/csv` 文件流。CSV 包含邮箱、余额、佣金、流量、到期时间、计划和订阅地址。订阅地址属于敏感凭据，导出文件必须按密钥材料保护。

范围规则与发邮件、封禁相同；未指定范围时默认导出全部用户。

### 10.3 永久删除用户

#### `POST /api/v2/{secure_path}/user/destroy`

```json
{
  "id": 123
}
```

该操作会在事务中删除：

- 用户；
- 用户订单；
- 邀请码；
- 用户统计；
- 工单；
- 用户订阅。

该操作不可通过 API 恢复。调用方应在执行前进行二次确认，并保存自己的外部业务审计记录。

## 11. 流量重置

### 11.1 手动重置用户流量

#### `POST /api/v2/{secure_path}/traffic-reset/reset-user`

```json
{
  "user_id": 123,
  "reason": "客服手动重置"
}
```

用户必须满足当前订阅的可重置条件。成功响应示例：

```json
{
  "message": "Reset success",
  "data": {
    "user_id": 123,
    "email": "user@example.com",
    "reset_time": "2026-07-29T12:00:00.000000Z",
    "next_reset_at": 1787961600
  }
}
```

相关查询接口：

| 方法 | 路径 | 说明 |
| --- | --- | --- |
| `GET` | `/traffic-reset/user/{userId}/history?limit=10` | 用户重置历史，`limit` 范围 1 至 50 |
| `GET` | `/traffic-reset/logs` | 重置日志，可按用户、日期、类型和来源筛选 |
| `GET` | `/traffic-reset/stats?days=30` | 最近 1 至 365 天统计 |

## 12. 已知不可用或不适合第三方使用的接口

### `POST /api/v2/{secure_path}/user/setInviteUser`

当前路由声明了该接口，但 `UserController` 没有对应的 `setInviteUser` 方法，调用会失败。不要接入该路由。

如需设置邀请关系，当前只能使用 `/user/update` 的 `invite_user_email`，并注意该接口在未携带有效邀请人邮箱时会清空原邀请关系。

## 13. 第三方集成建议

1. 只在服务端保存管理员 Token，使用专门的管理员账号，不与人工后台管理员共用账号。
2. 管理员 Token 当前是全权限、有效期一年的 Sanctum Token，没有针对用户管理的细粒度 scope。
3. 所有管理员 `POST` 请求会写入后台审计日志，但第三方仍应记录请求 ID、外部业务单号、目标用户、请求参数和响应。
4. API 全局限流当前没有启用，调用方应自行限速并使用指数退避。
5. 当前接口没有统一幂等键。创建用户可用邮箱作为业务唯一键；创建订单必须持久化 `trade_no`；删除、支付等操作重试前先查询状态。
6. 不要使用 `/user/update` 的“查询余额后覆盖”模拟充值。正式财务接入需要独立的原子增减余额和余额流水接口。
7. 优先用 `/user/subscription/fetch` 获取用户及订阅，它的余额和订阅结构比 `/user/getUserInfoById` 更适合外部消费。
8. 批量接口始终显式传 `scope`，禁止依赖默认值。

## 14. 典型调用流程

### 14.1 创建账号并直接分配订阅

```text
管理员登录
  -> POST /user/generate
  -> POST /user/subscription/fetch（按邮箱取得 user_id）
  -> POST /user/subscription/save
  -> 返回用户和订阅信息
```

适合赠送、迁移或后台人工分配权益，不会产生正常订单记录。

### 14.2 创建账号并记录一笔购买

```text
管理员登录
  -> POST /user/generate
  -> POST /order/assign
  -> 外部系统确认已经收款
  -> POST /order/paid
  -> POST /user/subscription/fetch（核对订阅）
```

`/order/paid` 只应在外部系统确认收款后调用。

## 15. cURL 示例

```bash
# 1. 登录
curl -X POST 'https://your-xboard.example.com/api/v1/passport/auth/login' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"admin@example.com","password":"your-password"}'

# 2. 创建用户
curl -X POST 'https://your-xboard.example.com/api/v2/SECURE_PATH/user/generate' \
  -H 'Authorization: Bearer ADMIN_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email_prefix":"newuser","email_suffix":"example.com","password":"StrongPassword123"}'

# 3. 按邮箱获取用户及订阅
curl -X POST 'https://your-xboard.example.com/api/v2/SECURE_PATH/user/subscription/fetch' \
  -H 'Authorization: Bearer ADMIN_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"email":"newuser@example.com"}'

# 4. 设置最终余额为 100（不是增加 100）
curl -X POST 'https://your-xboard.example.com/api/v2/SECURE_PATH/user/update' \
  -H 'Authorization: Bearer ADMIN_TOKEN' \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{"id":123,"balance":100}'
```

---

整理日期：2026-07-29。接口行为以当前仓库源码为准，升级 Xboard 后应重新核对路由、参数和返回结构。
