# {{POWER_NAME}} 快速入门指南

本指南将帮助您快速开始使用 {{POWER_NAME}} Power。

## 前置条件

在开始之前，请确保：
- [ ] 已安装 Kiro IDE
- [ ] 已安装 {{POWER_NAME}} Power
- [ ] 具备基本的 {{DOMAIN_KNOWLEDGE}} 知识

## 第一步：激活 Power

首先激活 {{POWER_NAME}} Power 以了解其功能：

```
action="activate", powerName="{{POWER_NAME}}"
```

这将返回：
- Power 的完整文档
- 可用工具列表
- 引导文件列表

## 第二步：了解可用工具

{{POWER_NAME}} 提供以下主要工具：

### 1. {{TOOL_NAME_1}}
**用途**: {{TOOL_PURPOSE_1}}
**何时使用**: {{WHEN_TO_USE_1}}

### 2. {{TOOL_NAME_2}}
**用途**: {{TOOL_PURPOSE_2}}
**何时使用**: {{WHEN_TO_USE_2}}

## 第三步：基础使用示例

### 示例 1: {{EXAMPLE_1_TITLE}}

**场景**: {{EXAMPLE_1_SCENARIO}}

**步骤**:
1. 准备数据：
   ```json
   {
     "{{PARAM_1}}": "{{EXAMPLE_VALUE_1}}",
     "{{PARAM_2}}": "{{EXAMPLE_VALUE_2}}"
   }
   ```

2. 调用工具：
   ```
   action="use",
   powerName="{{POWER_NAME}}",
   serverName="{{SERVER_NAME}}",
   toolName="{{TOOL_NAME_1}}",
   arguments={
     "{{PARAM_1}}": "{{EXAMPLE_VALUE_1}}",
     "{{PARAM_2}}": "{{EXAMPLE_VALUE_2}}"
   }
   ```

3. 查看结果并进行后续操作

### 示例 2: {{EXAMPLE_2_TITLE}}

**场景**: {{EXAMPLE_2_SCENARIO}}

**步骤**:
1. 收集输入数据：
   ```json
   {
     "{{PARAM_3}}": ["{{ITEM_1}}", "{{ITEM_2}}", "{{ITEM_3}}"],
     "{{PARAM_4}}": true
   }
   ```

2. 执行批量处理：
   ```
   action="use",
   powerName="{{POWER_NAME}}",
   serverName="{{SERVER_NAME}}",
   toolName="{{TOOL_NAME_2}}",
   arguments={
     "{{PARAM_3}}": ["{{ITEM_1}}", "{{ITEM_2}}", "{{ITEM_3}}"],
     "{{PARAM_4}}": true
   }
   ```

## 第四步：常见工作流程

### 工作流程 A: {{WORKFLOW_A_NAME}}
适用于 {{WORKFLOW_A_SCENARIO}}

1. **准备阶段**
   - 收集必要信息
   - 验证输入数据
   - 设置环境变量

2. **执行阶段**
   - 使用 {{TOOL_NAME_1}} 进行初始化
   - 使用 {{TOOL_NAME_2}} 处理数据
   - 验证结果

3. **完成阶段**
   - 保存结果
   - 清理临时文件
   - 生成报告

### 工作流程 B: {{WORKFLOW_B_NAME}}
适用于 {{WORKFLOW_B_SCENARIO}}

1. **分析阶段**
   - 评估当前状态
   - 确定处理策略

2. **处理阶段**
   - 批量执行操作
   - 监控进度

3. **验证阶段**
   - 检查结果质量
   - 处理异常情况

## 最佳实践

### 1. 输入验证
- 始终验证输入参数
- 使用适当的数据类型
- 处理边界情况

### 2. 错误处理
- 检查工具返回的状态
- 准备回退方案
- 记录错误信息

### 3. 性能优化
- 合理使用批量操作
- 避免不必要的重复调用
- 监控资源使用

## 故障排除

### 问题：工具调用失败
**可能原因**:
- 参数格式错误
- 权限不足
- 网络连接问题

**解决步骤**:
1. 检查参数格式
2. 验证权限设置
3. 测试网络连接
4. 查看错误日志

### 问题：结果不符合预期
**可能原因**:
- 输入数据质量问题
- 配置参数不当
- 版本兼容性问题

**解决步骤**:
1. 验证输入数据
2. 检查配置参数
3. 确认版本兼容性
4. 参考文档示例

## 下一步

完成基础使用后，您可以：

1. **阅读高级指南**: 
   ```
   action="readSteering",
   powerName="{{POWER_NAME}}",
   steeringFile="advanced-usage.md"
   ```

2. **探索更多功能**: 查看完整的工具列表和参数选项

3. **集成到工作流程**: 将 {{POWER_NAME}} 集成到您的日常开发流程中

4. **自定义配置**: 根据需要调整 MCP 配置和环境变量

## 获取帮助

如果遇到问题：
- 查看 Power 文档：`action="activate", powerName="{{POWER_NAME}}"`
- 阅读故障排除指南
- 检查日志文件
- 联系支持团队

---

*祝您使用愉快！如有问题，请随时寻求帮助。*