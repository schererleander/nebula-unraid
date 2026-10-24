# Nebula for Unraid

| Nebula | Settings |
|:------:|:--------:|
| <img src="https://github.com/user-attachments/assets/0af10dd4-ded5-4201-bbf7-e7bef11318b3" width="400" alt="Nebula" /> | <img src="https://github.com/user-attachments/assets/af47aa5a-e7da-4106-9bc9-7d830a373ab9" width="400" alt="Settings" /> |


Connect your Unraid server to a Nebula mesh network.

Download `nebula.plg` from [GitHub Releases](https://github.com/schererleander/nebula-unraid/releases/latest)
and install it through Unraid’s Plugin Manager. Releases also include a `.tar.gz`
archive and the Slackware `.txz` package. The `.plg` embeds that package; Nebula
and yq are downloaded during installation.

To install from a URL in Unraid's **Plugins → Install Plugin**, use:

```text
https://github.com/schererleander/nebula-unraid/releases/latest/download/nebula.plg
```

To publish, push your changes to the default branch and run **Actions → Draft
release → Run workflow**. The workflow assigns a UTC timestamp version such as
`2026.10.24.1430` to the generated plugin, packages, and release tag. No manual
version bump is needed. Open the draft link in the summary, review it, and publish
it as the latest stable release. Versions use the current UTC date, hour, and minute.
