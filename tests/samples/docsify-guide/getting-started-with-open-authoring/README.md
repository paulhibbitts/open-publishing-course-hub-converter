# Getting Started with Open Authoring

<small>Section 3</small>

> [!TIP]
> **Learning Objectives**
>
> - Set up an edit link to invite reader contributions
> - Compare how GitHub, Codeberg and GitLab handle proposed changes
> - Describe the fork-and-propose workflow for GitHub visitors

Open authoring closes the loop between publishing content openly and actively inviting others to improve it. The goal isn't just making content readable – it's making it editable.

## The Edit Link

Docsify-This can add a link to each page that connects visitors to the site's Markdown files in your Git repository. Add the `edit-link` option to the site's address, set to your repository (for example `&edit-link=https://github.com/your-name/your-repo`), and `edit-link-text` to change the link's label.

A single link serves two audiences:

- **Contributors with repository access** can edit the file directly
- **Everyone else** sees the file in the repository viewer, where they can fork it and propose changes

## Proposing Changes on Different Platforms

| Platform | Visitors without repository access |
| --- | --- |
| GitHub | Sign in, then select the edit (pencil) button – GitHub forks the repository and guides them through opening a pull request |
| Codeberg | Sign in, fork the repository, then open a pull request |
| GitLab | Sign in, fork the repository, then open a merge request |

GitHub handles visitors without repository access most smoothly, making it well suited for open authoring workflows.

## Asking Readers to Help

The simplest invitation is a single sentence at the top of your reader home page:

> Found an error or want to suggest an improvement? Use the link on any page to view the source and propose a change.

Readers who are comfortable with GitHub can open a pull request. Those who aren't can email you the suggested change. Either way, the source file is visible and accessible.
