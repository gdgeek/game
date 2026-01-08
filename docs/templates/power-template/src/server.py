#!/usr/bin/env python3
"""
{{POWER_NAME}} MCP Server

{{POWER_DESCRIPTION}}
"""

import asyncio
import json
import logging
from typing import Any, Dict, List, Optional
from mcp.server import Server
from mcp.server.stdio import stdio_server
from mcp.types import Tool, TextContent, ImageContent, EmbeddedResource

# 配置日志
logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

# 创建服务器实例
app = Server("{{POWER_NAME}}")

@app.list_tools()
async def list_tools() -> List[Tool]:
    """返回可用工具列表"""
    return [
        Tool(
            name="{{TOOL_NAME_1}}",
            description="{{TOOL_DESCRIPTION_1}}",
            inputSchema={
                "type": "object",
                "properties": {
                    "{{PARAM_1}}": {
                        "type": "string",
                        "description": "{{PARAM_1_DESCRIPTION}}"
                    },
                    "{{PARAM_2}}": {
                        "type": "string",
                        "description": "{{PARAM_2_DESCRIPTION}}",
                        "default": "{{DEFAULT_VALUE}}"
                    }
                },
                "required": ["{{PARAM_1}}"]
            }
        ),
        Tool(
            name="{{TOOL_NAME_2}}",
            description="{{TOOL_DESCRIPTION_2}}",
            inputSchema={
                "type": "object",
                "properties": {
                    "{{PARAM_3}}": {
                        "type": "array",
                        "items": {"type": "string"},
                        "description": "{{PARAM_3_DESCRIPTION}}"
                    },
                    "{{PARAM_4}}": {
                        "type": "boolean",
                        "description": "{{PARAM_4_DESCRIPTION}}",
                        "default": False
                    }
                },
                "required": ["{{PARAM_3}}"]
            }
        )
    ]

@app.call_tool()
async def call_tool(name: str, arguments: Dict[str, Any]) -> List[TextContent | ImageContent | EmbeddedResource]:
    """处理工具调用"""
    try:
        if name == "{{TOOL_NAME_1}}":
            return await handle_{{TOOL_NAME_1}}(arguments)
        elif name == "{{TOOL_NAME_2}}":
            return await handle_{{TOOL_NAME_2}}(arguments)
        else:
            raise ValueError(f"未知工具: {name}")
    
    except Exception as e:
        logger.error(f"工具调用失败 {name}: {str(e)}")
        return [TextContent(
            type="text", 
            text=f"错误: {str(e)}"
        )]

async def handle_{{TOOL_NAME_1}}(arguments: Dict[str, Any]) -> List[TextContent]:
    """处理 {{TOOL_NAME_1}} 工具调用"""
    {{PARAM_1}} = arguments.get("{{PARAM_1}}")
    {{PARAM_2}} = arguments.get("{{PARAM_2}}", "{{DEFAULT_VALUE}}")
    
    # 验证输入
    if not {{PARAM_1}}:
        raise ValueError("{{PARAM_1}} 不能为空")
    
    # 执行主要逻辑
    logger.info(f"执行 {{TOOL_NAME_1}}: {{PARAM_1}}={{{PARAM_1}}}, {{PARAM_2}}={{{PARAM_2}}}")
    
    # TODO: 实现具体功能
    result = {
        "status": "success",
        "message": f"成功处理 {{{PARAM_1}}}",
        "data": {
            "{{PARAM_1}}": {{PARAM_1}},
            "{{PARAM_2}}": {{PARAM_2}},
            "timestamp": "{{TIMESTAMP}}"
        }
    }
    
    return [TextContent(
        type="text",
        text=json.dumps(result, ensure_ascii=False, indent=2)
    )]

async def handle_{{TOOL_NAME_2}}(arguments: Dict[str, Any]) -> List[TextContent]:
    """处理 {{TOOL_NAME_2}} 工具调用"""
    {{PARAM_3}} = arguments.get("{{PARAM_3}}", [])
    {{PARAM_4}} = arguments.get("{{PARAM_4}}", False)
    
    # 验证输入
    if not {{PARAM_3}}:
        raise ValueError("{{PARAM_3}} 不能为空")
    
    # 执行主要逻辑
    logger.info(f"执行 {{TOOL_NAME_2}}: 处理 {len({{PARAM_3}})} 个项目")
    
    results = []
    for item in {{PARAM_3}}:
        # TODO: 实现具体处理逻辑
        processed_item = {
            "original": item,
            "processed": f"处理后的_{item}",
            "{{PARAM_4}}": {{PARAM_4}}
        }
        results.append(processed_item)
    
    response = {
        "status": "success",
        "message": f"成功处理 {len(results)} 个项目",
        "results": results
    }
    
    return [TextContent(
        type="text",
        text=json.dumps(response, ensure_ascii=False, indent=2)
    )]

# 辅助函数
def validate_input(data: Any, schema: Dict[str, Any]) -> bool:
    """验证输入数据是否符合模式"""
    # TODO: 实现输入验证逻辑
    return True

def format_error(error: Exception) -> str:
    """格式化错误消息"""
    return f"错误类型: {type(error).__name__}, 消息: {str(error)}"

async def main():
    """主函数"""
    logger.info("启动 {{POWER_NAME}} MCP 服务器...")
    
    try:
        async with stdio_server() as streams:
            await app.run(*streams)
    except Exception as e:
        logger.error(f"服务器启动失败: {str(e)}")
        raise

if __name__ == "__main__":
    # 支持调试模式
    import sys
    if "--debug" in sys.argv:
        logging.getLogger().setLevel(logging.DEBUG)
        logger.debug("调试模式已启用")
    
    asyncio.run(main())