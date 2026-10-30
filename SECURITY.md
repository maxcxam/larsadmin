# Security Policy

## Supported versions

Security fixes are released for the latest minor and patch release of the current major version.

| Version | Supported |
| --- | --- |
| 1.x (latest minor/patch) | Yes |
| 1.x (older minor/patch releases) | No, update to the latest 1.x |
| < 1.0 | No |

When a new major version is released, older majors stop receiving security fixes.

## Reporting a vulnerability

Please **do not open a public issue, discussion or pull request** for a security problem.

Report it privately through GitHub private vulnerability reporting: open
<https://github.com/maxcxam/larsadmin/security/advisories/new> (or the repository's **Security**
tab, then **Report a vulnerability**) and fill in the form. Only the maintainer can see the report.

Please include:

- the affected Larsadmin version, and the PHP and Laravel versions;
- a description of the problem and its impact (what an attacker can do, and with what access);
- steps to reproduce or a minimal proof of concept, such as an Entity, policy or request;
- any suggested fix or mitigation, if you have one.

## What happens next

- You can expect a first reply within about **7 days**.
- The report is confirmed or declined, and you are kept informed while a fix is prepared.
- Fixes are released as **patch versions** of the supported release line. Each fix is announced in a
  **GitHub Security Advisory** and in a `Security` entry in [CHANGELOG.md](CHANGELOG.md).
- With your permission, you are credited in the advisory and the changelog. Tell us if you prefer
  to stay anonymous.

## Українською

Не відкривайте публічних issue щодо вразливостей. Повідомляйте про них приватно через GitHub
private vulnerability reporting: <https://github.com/maxcxam/larsadmin/security/advisories/new>
(вкладка **Security**, кнопка **Report a vulnerability**). Вкажіть версії Larsadmin, PHP і Laravel,
опис проблеми, кроки відтворення. Першу відповідь ви отримаєте орієнтовно протягом 7 днів.
Виправлення виходять патч-версіями гілки 1.x з GitHub Security Advisory і записом `Security`
у [CHANGELOG.md](CHANGELOG.md); за вашою згодою вас буде згадано як автора повідомлення.
