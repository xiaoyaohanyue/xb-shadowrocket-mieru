# Shadowrocket Mieru 支持

为 Xboard 的 Shadowrocket 订阅追加 Mieru 节点下发支持。

## 实现方式

当前 Xboard 的 `Shadowrocket` 协议生成器没有包含 Mieru。插件会在 Shadowrocket 订阅请求进入协议生成前接管响应：

- 复用 Xboard 原有 Shadowrocket 生成器输出 Shadowsocks、VMess、VLESS、Trojan、Hysteria、TUIC、AnyTLS、Socks 等节点。
- 将可用的 Mieru 节点追加为官方 Mieru 简单分享链接 `mierus://...`。
- 默认使用节点名称作为 `profile` 参数，避免 Shadowrocket 中所有 Mieru 节点都显示为 `default`。
- 最终仍按 Shadowrocket 订阅格式返回 base64 文本。

## 注意事项

- 只对 `flag=shadowrocket` 或 User-Agent 包含 `shadowrocket` 的订阅请求生效。
- Mieru 节点会使用 Xboard 生成的用户节点密码作为 username 和 password。
- Shadowrocket 会把 Mieru 链接里的 `profile` 当作显示名称；建议保持“使用节点名作为 Profile”开启。
- Mieru 的 `traffic_pattern` 会作为 `traffic-pattern` 参数下发。
- 如果后续 Xboard 原生支持 Shadowrocket Mieru，默认配置会自动跳过插件接管。

## 更新记录

### 1.0.3

- 修复插件接管 Shadowrocket 订阅后，“剩余流量”和“套餐到期”信息节点丢失的问题。
- 保持“距离下次重置剩余”和过滤线路提示与 Xboard 原生订阅流程一致。
- 为信息节点分配独立占位端口，避免 Shadowrocket 按连接参数去重后不显示。

> Xboard 使用 Octane 常驻进程。升级插件后需要重载 Octane 或重启 Xboard 容器，才能加载新的 `Plugin.php`。
