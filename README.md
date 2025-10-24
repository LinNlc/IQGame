# Twine/SugarCube 项目说明 & Codex 协作提示（v0.1）

> 目标：**保持 index.html 可被 Twine 导入编辑**（Story Archive 结构完好），在不破坏现有剧情与埋点体系的前提下，逐步优化交互与可维护性。

---

## 1) 项目概览
- 引擎：Twine 2（creator-version 2.10.0），格式：SugarCube（format-version 2.37.3），故事名：**保密加强版**。
- 前端：单页 `index.html`，包含 *User Stylesheet* 与 *User Script* 两个可安全扩展的挂点。
- 后端：`/api` 下 PHP 接口（登录鉴权、事件打点入库），`/admin` 为简易埋点查看台。

> **重要兼容性约束**
> - 不删除/重命名 `<tw-storydata>`、`#twine-user-stylesheet`、`#twine-user-script` 等标识节点。
> - 不移除 SugarCube 运行时依赖，不改动 `tw-storydata` 属性（如 `ifid`、`format-version`）。
> - 所有新增逻辑，应优先写入 *User Script / User Stylesheet*，避免“散落在系统脚本”导致无法回导 Twine。

---

## 2) 目录结构与职责
```
.
├─ admin/
│  └─ index.php          # 简易后台：口令登录 + 事件检索/列表（最近500条）
├─ api/
│  ├─ auth.php           # 用户登录接口（POST JSON: username/password；也支持 ping）
│  ├─ db.php             # PDO 连接（容器内地址 + 兜底外部地址），统一 $pdo
│  ├─ dbcheck.php        # 数据库连通性检查
│  ├─ log.php            # 事件打点写库（start/node/ending/choice）
│  └─ seed_users.php     # 批量写入测试用户（password_hash）
├─ assets/
│  └─ img/               # 图片资源（登录背景等）
└─ index.html            # Twine 游戏本体（可回导编辑）
```

---

## 3) 前端（index.html）的关键结构

### 3.1 Twine/SugarCube 元信息
- `<tw-storydata>`：包含故事元数据与所有 Passage；**保持原样**以便 Twine 导入。
- *User Stylesheet*（`#twine-user-stylesheet`）：自定义样式（如隐藏侧栏、登录页背景容器等）。
- *User Script*（`#twine-user-script`）：配置与逻辑集中处。

### 3.2 运行配置（节选）
- `Config.passages.start = 'Login'`：从登录页起。
- 关闭回退与多历史堆栈：`Config.history.controls=false`, `Config.history.maxStates=1`。
- 存档限制：`Config.saves.maxAutoSaves=1`, `Config.saves.maxSlotSaves=0`。

### 3.3 可调参数（集中）
- `setup.LoginBG_URL = ''`：登录页背景图（默认留空，使用渐变层，如需自定义请填入图片地址）。
- `setup.AppBG_URL = ''`：非登录页面的全局背景图（留空时同样沿用渐变层，可按需覆写）。
- `setup.WM = { opacity, cols, rowHeight, fontSize, color }`：用户名水印全局参数（进入后渲染为全屏网格）。

### 3.4 埋点与关键节点
- 关键结局/节点集合：`setup.KeyTrack.endings = [...]`；`setup.KeyTrack.nodes = [...]`。
- 事件上报：
  - 故事就绪（已登录）打一次 `start`。
  - 每个 Passage 初始化时，若命中关键节点或结局，调用 `logEvent(type,label,passage)` 上报。

### 3.5 登录与会话
- `setup.tryLogin(u,p,tipEl)`：POST `/api/auth.php`（飞书多维表格认证）成功后：
  - 取后端返回的 `displayName || username` 写入 `State.variables.user` 与 `localStorage('tw_user')`；
  - 渲染水印 `setup.renderWatermark()`；
  - 跳转到 `MENU_PASSAGE` 或 `Main`。
- `<<logout>>` 宏：清理登录状态并回到 Login。

> **前端与后端的耦合点**
> - `setup.API_BASE = '/api/'`
> - 打点接口固定 `/api/log.php`；鉴权接口 `/api/auth.php`。

---

## 4) 后端 API（/api）

### 4.1 登录 `POST /api/auth.php`
- 入参：`{ "username": string, "password": string }`（JSON）。
- 服务端：调用飞书多维表格（app_token=`RGk2bAFnvakyPWs7Uhlc05sbnoe`，用户表 `tbltiM3U0f6O97SP`，视图 `vewQOELBge`）检索账号；支持明文或 `password_hash()` 存储的口令。
- 返回：`{ ok: true, user: { id: record_id, username, displayName } }` 或错误码（`invalid_credentials` 等）。
- 依赖环境变量：
  - `FEISHU_APP_SECRET`（必填，用于换取 tenant_access_token；亦兼容 `LARK_APP_SECRET`/`APP_SECRET`）。
  - 可选覆盖：`FEISHU_APP_TOKEN`、`FEISHU_USER_TABLE`、`FEISHU_USER_VIEW`、`FEISHU_USERNAME_FIELD`、`FEISHU_PASSWORD_FIELD`、`FEISHU_DISPLAY_FIELD`。
- 默认列名尝试顺序：账号 → 用户名 → 登录账号；密码 → 口令 → password（可继续用环境变量覆盖）。
- 附带：`GET ?ping=1` 快速自测；需启用 PHP cURL 扩展。

### 4.2 事件上报 `POST /api/log.php`
- 入参（JSON）：`token(≤64)`, `name(≤64)`, `type(≤16 ∈ start|node|ending|choice)`, `label(≤128)`, `passage(≤128)`。
- 服务端：
  - `player(token,name)` UPSERT（更新 `last_seen`）。
  - `event(player_token,name,event_type,label,passage,ua,ip)` INSERT。
  - 统一事务提交，返回 `{ ok: true }`。

### 4.3 数据库连接 `api/db.php`
- PDO MySQL，显式设置 `ERRMODE_EXCEPTION / FETCH_ASSOC / 禁用模拟预处理`。
- 先尝试容器内主机；失败再尝试外部地址作为兜底。**请勿将真实凭据提交到公开仓库**。
- 备注：登录鉴权已切换至飞书多维表格，数据库仍用于事件打点等业务表。

### 4.4 工具脚本
- `dbcheck.php`：输出当前连接信息（用于部署核对）。
- `seed_users.php`：批量插入演示用户（使用 `password_hash` 生成）。

---

## 5) 后台查看（/admin/index.php）
- 会话口令登录（`?p=xxxx` 一次写入 session，后续免带参数；支持登出）。
- 支持按玩家名/token 模糊匹配，按 `type`（node/ending/start/choice）筛选。
- 列表最多显示最近 500 条。

`ending_view.php` 为早期 CSV 版查看页（读取 `logs.csv`），保留做对比/兜底。

---

## 6) 数据模型（据代码推断）
- `player(token PK, name, last_seen, ...)`
- `event(id PK, player_token FK, name, event_type, label, passage, ua, ip, created_at, ...)`

> 若尚未建表，请在仓库加入 `schema.sql`，并在 README 标注初始化命令；或在 `db.php` 添加一次性迁移脚本（上线后移除）。

---

## 7) 给 Codex 的**固定提示词模板**（直接复制使用）

> **系统指令（保持不变）**
> 你是资深前端工程师 & Twine/SugarCube 专家。你的修改**必须**保证：
> 1) `index.html` 仍可被 Twine 导入编辑（`<tw-storydata>` 与 `#twine-user-*` 节点保留且结构不破坏）。  
> 2) 所有新增/改动优先写入 **User Script / User Stylesheet** 区域；**不要**改动 SugarCube 核心脚本。  
> 3) 不影响 `/api/auth.php` 与 `/api/log.php` 的调用约定；不移除现有埋点。  
> 4) 输出 **最小化差异**（建议以“补丁片段/替换块”的形式给出），并包含**自测步骤**。

> **本次需求**
> - {在此粘贴我本轮的变化需求与验收标准，如：
>   1) 登录页交互优化（键盘回车触发、错误提示强化）；
>   2) 新增关键节点 `X/Y` 进入时上报；
>   3) 在 `Main` 加入帮助弹层；
>   …… }
>
> **上下文（只读）**
> - Twine 版本：2.10.0；SugarCube：2.37.3；起始 Passage：`Login`；`setup.API_BASE='/api/'`。
> - 关键集合：`setup.KeyTrack.endings`, `setup.KeyTrack.nodes`（已有内容请保留并在此基础上扩展）。
> - 登录成功：写入 `State.variables.user` 与 `localStorage('tw_user')`，需保持兼容。

> **输出要求**
> 1) 指明**修改位置**（`#twine-user-script` / `#twine-user-stylesheet` / 新增 Passage 等），给出**完整替换片段**。  
> 2) 列出**不破坏 Twine 导入**的检查项（是否仍存在 `<tw-storydata>`、脚本/样式节点 ID 未改、能在 Twine 打开并预览）。  
> 3) 给出**自测步骤**（含：登录→进入→触发节点→检查打点请求与后台列表）。  
> 4) 若需要后端配合，请**最小化改动**并标明接口契约；避免硬编码敏感配置。

---

## 8) 变更记录 / 进度
- **v0.4（2025-10-27）** 去除 SugarCube 默认封面层并默认使用渐变背景；保证登录壳层完整显示。
- **v0.3（2025-10-26）** 调整 16:9 外壳层级并恢复全局背景；登录页布局放大防遮挡；默认优先读取“账号/密码”列。
- **v0.2（2025-10-25）** 登录改接飞书多维表格；首页/登录页 16:9 视觉重构与交互优化。
- **v0.1（2025-10-24）** 建立 Codex 协作基线文档：项目结构梳理、接口契约、固定提示词模板。

> **维护约定**：每次完成一轮需求，请在此处追加版本号、日期与要点（同时将“本次需求”粘回到上方模板供下一轮迭代）。

---

## 9) 本地自测清单（提交前必跑）
- [ ] 浏览器控制台无报错；Twine 运行页面可进入 Login。
- [ ] 输入飞书多维表格（用户表 `tbltiM3U0f6O97SP`）中存在的账号 → 登录成功，出现水印。
- [ ] 进入 Main / 普通 Passage，无异常；关键 Passage 触发上报。
- [ ] `/admin` 可按 type/q 筛选到最新记录。
- [ ] 将改后的 `index.html` 导入 Twine → 正常显示结构与 Passages。

---

### 附注：安全与配置
- 生产环境请改为**环境变量**管理数据库凭据；仓库内避免明文。
- 飞书多维表格应用密钥同样通过环境变量（如 `FEISHU_APP_SECRET`）注入，禁止写入仓库。
- 若需跨域部署，请在后端完善允许域名白名单策略。
