# To Install it manually #
- Unzip the plugin in the moodle .../customfield directory.

# To Use it #
Just add a custom field of type file to any object that supports it e.g. a course.

# To style it #
If you want to override how a specific instance of this custom field gets rendered (e.g. embed an image tag rather than a list of links) you can do this within your theme as follows (boost used as an example... don't hack core!).

Create a file to override the custom field renderer here:
```
theme/boost/classes/output/core_customfield_renderer.php
```

This file can then contain a function that will override how a specific custom field will get displayed - the function must be named 'render_customfield' followed by the component (e.g. core_course) and area (e.g. 'course) of the custom field handler followed by the shortname of the custom field (e.g. thumbnailimage). See example implementation below: 

```
namespace theme_boost\output;

use core_customfield\output\renderer;

defined('MOODLE_INTERNAL') || die;

class core_customfield_renderer extends renderer {
    public function render_customfield_core_course_course_thumbnailimage($model) {
        return $this->render_from_template('customfield_file/exportvalue', $model);
    }
}
```

Author
------

The module has been written and is currently maintained by Andrew Hancox on behalf of [Open Source Learning](https://opensourcelearning.co.uk).

Useful links
------------

* [Open Source Learning](https://opensourcelearning.co.uk)
* [Bug tracker](https://github.com/andrewhancox/moodle-customfield_file/issues)
