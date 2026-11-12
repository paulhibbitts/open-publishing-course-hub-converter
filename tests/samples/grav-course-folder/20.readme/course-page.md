---
title: Read Me
published: true
hide_page_title: true
---

## Create, Publish, Share and Collaborate using the Grav Open Course Space!  

1. Create a new [Git](https://git-scm.com/) repository (hosted on [GitHub](https://github.com/), [GitLab](https://about.gitlab.com/), [Bitbucket](https://bitbucket.org/) or your own server) with at least one commit.
2. Set up Git Sync with the step-by-step instructions in the Admin Panel's [Git Sync](../../admin/plugin/git-sync) menu item.
3. Configure the [Git Sync Link](../../admin/themes/mytheme) settings in the Theme options to change the display and functionality of the automatically displayed Git Sync Link.

## Extend the Capabilities of Your Learning Management System (LMS)  

By adding the URL parameter (i.e. flag) `chromeless:true` to any Open Course Space page you can display that page's content without the global header image, site navigation bar, sidebar and footer - a great way to seamlessly embed Open Course Space pages into other systems.  

For example, the URL [demo.hibbittsdesign.org/grav-open-course-space/cpt363-basic/schedule](https://demo.hibbittsdesign.org/grav-open-course-space/cpt363-basic/schedule) displays the standard Open Course Space page but the URL [demo.hibbittsdesign.org/grav-open-course-space/cpt363-basic/schedule/chromeless:true](https://demo.hibbittsdesign.org/grav-open-course-space/cpt363-basic/schedule/chromeless:true) will only display that page's content. Any links between Open Course Space pages will result in pages being displayed in the same manner.

## Modules, Weekly Units, and a Blog

Each course can be organised its own way. Modules work as in Grav Helios Course Hub: a Modules page with a card for each module, a list of modules beside every module page, and Previous and Next buttons through every module and page. A blog page can instead list weekly units, newest first. The site also has a [Blog](/blog) shared by all its courses, for news, tips and reflections.

The example courses show each one: [CPT363-1](/cpt363-basic/modules) and [CPT363-2](/cpt363-intermediate/modules) are organised by modules, and [CPT363-3](/cpt363-advanced/weekly-units) is organised by weekly units.

## Bring Courses Over from Your LMS

Have a course in Canvas, Moodle, Brightspace, or Blackboard? Export it as a Common Cartridge file, and the free [Common Cartridge Converter](https://common-cartridge-converter.hibbittsdesign.org/) (beta) turns it into a Grav course organised by modules – see [an example conversion](https://demo.hibbittsdesign.org/grav-open-course-hub-common-cartridge-it2dda/). It's made for Open Course Hub, so for this site, move its pages into a course folder with a `course.md` page.

## Give a Course Its Own Address

Want a course to open at its own address, such as `cpt363.example.edu`? Point a second domain or subdomain at this site's folder (your web host's control panel can do this), then create `user/env/<that address>/config/system.yaml` with:

```yaml
home:
  alias: '/my-course/home'
```

The site's main address still opens as before, and both addresses share the same pages. A `site.yaml` beside it can also give that address its own description and share image – see Grav's [Environment Configuration](https://learn.getgrav.org/2/advanced/environment-config).

## What Else Can the Open Course Space do for You? ##
Learn more about the capabilities of the Open Course Space project in its [README on GitHub](https://github.com/hibbitts-design/grav-skeleton-open-course-space#readme).

<hr>

Want to no longer display this page on your site?  
Delete the page 'Read Me' in your [Admin Pages panel](../../admin/pages) or set the page's 'Published' option under the 'Options' Tab to 'No'.
