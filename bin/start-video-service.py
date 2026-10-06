"""Run the private MoneyPrinterTurbo service with credentials kept in memory."""
import os
import sys
from pathlib import Path

root = Path(os.environ.get('MONEYPRINTER_ROOT', '/workspace/.setup/MoneyPrinterTurbo'))
os.chdir(root)
sys.path.insert(0, str(root))
from app.config import config

config.app['llm_provider'] = 'openai'
config.app['openai_model_name'] = os.environ.get('VIDEO_LLM_MODEL', 'gpt-4o-mini')
config.app['openai_base_url'] = 'https://api.openai.com/v1'
if os.environ.get('VIDEO_LLM_KEY'):
    config.app['openai_api_key'] = os.environ['VIDEO_LLM_KEY']
if os.environ.get('VIDEO_PEXELS_KEY'):
    config.app['pexels_api_keys'] = [os.environ['VIDEO_PEXELS_KEY']]
config.app['max_concurrent_tasks'] = 2
config.app['max_queued_tasks'] = 20
config.app['upload_post_auto_upload'] = False

import uvicorn
uvicorn.run('app.asgi:app', host='127.0.0.1', port=8080, log_level='warning')
