#!/usr/bin/env bash
#
# zc-git-post-deploy.sh - OpenAI GPT Chatbot
#
# Auto-discovered by zencart_git after each successful deploy
# (see deploy/post-deploy-hooks.php and scripts/run-post-deploy-hooks.sh).
#
# Rebuilds the chatbot compiled knowledge base from define-pages + EZ-pages
# so merchant FAQ / policy edits are searchable without a manual admin click.
#
# Env (set by the deploy runner):
#   ZC_PATH            Absolute catalog / docroot path (required)
#   ZC_GIT_HTTP_HOST   Optional host for configure.php DB selection
#   ZC_GIT_BRANCH      Optional branch name (logged only)
#
set -eu

ZC="${ZC_PATH:-}"
if [[ -z "${ZC}" ]] || [[ ! -d "${ZC}" ]]; then
	echo "openai-gpt-chatbot post-deploy: ZC_PATH missing or not a directory"
	exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPILE_KB="${SCRIPT_DIR}/compile_kb.php"
if [[ ! -f "${COMPILE_KB}" ]]; then
	echo "openai-gpt-chatbot post-deploy: compile_kb.php not found next to this script (${SCRIPT_DIR})"
	exit 1
fi

PHP_BIN=""
for candidate in php /usr/local/bin/php /usr/bin/php; do
	if command -v "${candidate}" >/dev/null 2>&1; then
		if "${candidate}" -r 'exit(PHP_SAPI==="cli"?0:1);' >/dev/null 2>&1; then
			PHP_BIN="${candidate}"
			break
		fi
	fi
done
if [[ -z "${PHP_BIN}" ]]; then
	echo "openai-gpt-chatbot post-deploy: no CLI php binary found on PATH"
	exit 1
fi

LOG_DIR="${ZC}/logs"
mkdir -p "${LOG_DIR}" 2>/dev/null || true
LOG_FILE="${LOG_DIR}/chatbot_kb_build.log"

echo "openai-gpt-chatbot post-deploy: rebuilding KB catalog=${ZC} branch=${ZC_GIT_BRANCH:-} host=${ZC_GIT_HTTP_HOST:-}"

if [[ -n "${ZC_GIT_HTTP_HOST:-}" ]]; then
	set +e
	"${PHP_BIN}" "${COMPILE_KB}" --catalog="${ZC}" --host="${ZC_GIT_HTTP_HOST}" >> "${LOG_FILE}" 2>&1
	rc=$?
	set -e
else
	set +e
	"${PHP_BIN}" "${COMPILE_KB}" --catalog="${ZC}" >> "${LOG_FILE}" 2>&1
	rc=$?
	set -e
fi

if [[ ${rc} -eq 0 ]]; then
	echo "openai-gpt-chatbot post-deploy: KB rebuild OK (log: ${LOG_FILE})"
else
	echo "openai-gpt-chatbot post-deploy: KB rebuild failed (exit ${rc}; log: ${LOG_FILE})"
fi
exit "${rc}"
