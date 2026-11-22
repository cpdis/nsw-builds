# pstack model configuration. One line per role. Delete a line to fall back to the skill default.
# Written by hand from /pstack:setup-pstack defaults, mapped per pstack's Claude Code adapter.
# Kept in the repo (not ~/.claude/rules) so cloud sessions get it too. Rerun /pstack:setup-pstack to change it.
# `inherit-parent` as a value: the role runs on the parent chat model (omit Agent `model`). The Gemini panel seat has no Claude Code equivalent, so it inherits.
# defaults: 3
# budget: large (xhigh)
feature, refactoring: opus xhigh
bug-fix: opus xhigh
perf-issue: opus xhigh
hillclimb: opus xhigh
judgment and prose: opus xhigh
arena runners: fable high, opus high, inherit-parent
arena cross-judge pool: opus high, inherit-parent
swarm workers: opus high
interrogate reviewers: fable high, opus high, inherit-parent
