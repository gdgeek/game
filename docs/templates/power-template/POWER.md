# {{POWER_NAME}}

{{POWER_DESCRIPTION}}

## 功能特性

- {{FEATURE_1}}
- {{FEATURE_2}}
- {{FEATURE_3}}

## 关键词
{{KEYWORDS}} - 当用户消息中包含这些关键词时，应立即激活此 Power

## 快速开始

1. 激活 Power：
   ```
   action="activate", powerName="{{POWER_NAME}}"
   ```

2. 查看可用工具和引导文件

3. 使用工具：
   ```
   action="use", 
   powerName="{{POWER_NAME}}", 
   serverName="{{SERVER_NAME}}", 
   toolName="{{TOOL_NAME}}", 
   arguments={...}
   ```

## 主要工具

### {{TOOL_NAME_1}}
**描述**: {{TOOL_DESCRIPTION_1}}

**参数**:
- `{{PARAM_1}}` (必需): {{PARAM_1_DESCRIPTION}}
- `{{PARAM_2}}` (可选): {{PARAM_2_DESCRIPTION}}

**示例**:
```json
{
  "{{PARAM_1}}": "{{EXAMPLE_VALUE_1}}",
  "{{PARAM_2}}": "{{EXAMPLE_VALUE_2}}"
}
```

### {{TOOL_NAME_2}}
**描述**: {{TOOL_DESCRIPTION_2}}

**参数**:
- `{{PARAM_3}}` (必需): {{PARAM_3_DESCRIPTION}}

**示例**:
```json
{
  "{{PARAM_3}}": "{{EXAMPLE_VALUE_3}}"
}
```

## 工作流程示例

### 基础工作流程
1. 激活 Power
2. 使用 `{{TOOL_NAME_1}}` 进行初始设置
3. 使用 `{{TOOL_NAME_2}}` 执行主要任务
4. 查看结果和后续步骤

### 高级工作流程
详细的多步骤流程，包含错误处理和最佳实践。

## 配置选项

### 环境变量
- `{{ENV_VAR_1}}`: {{ENV_VAR_1_DESCRIPTION}}
- `{{ENV_VAR_2}}`: {{ENV_VAR_2_DESCRIPTION}}

### MCP 配置
```json
{
  "mcpServers": {
    "{{POWER_NAME}}": {
      "command": "uvx",
      "args": ["{{PACKAGE_NAME}}@latest"],
      "env": {
        "{{ENV_VAR_1}}": "{{DEFAULT_VALUE}}",
        "FASTMCP_LOG_LEVEL": "ERROR"
      },
      "disabled": false,
      "autoApprove": ["{{TOOL_NAME_1}}", "{{TOOL_NAME_2}}"]
    }
  }
}
```

## 故障排除

### 常见问题

#### 问题 1: {{COMMON_ISSUE_1}}
**症状**: {{SYMPTOM_1}}
**解决方案**: {{SOLUTION_1}}

#### 问题 2: {{COMMON_ISSUE_2}}
**症状**: {{SYMPTOM_2}}
**解决方案**: {{SOLUTION_2}}

### 调试技巧
- 启用详细日志记录
- 检查网络连接
- 验证权限设置

## 最佳实践

1. **{{BEST_PRACTICE_1}}**: {{BEST_PRACTICE_1_DESCRIPTION}}
2. **{{BEST_PRACTICE_2}}**: {{BEST_PRACTICE_2_DESCRIPTION}}
3. **{{BEST_PRACTICE_3}}**: {{BEST_PRACTICE_3_DESCRIPTION}}

## 相关资源

- [官方文档]({{DOCS_URL}})
- [GitHub 仓库]({{GITHUB_URL}})
- [示例项目]({{EXAMPLES_URL}})

---

*版本: {{VERSION}} | 最后更新: {{LAST_UPDATED}}*