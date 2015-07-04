define([], function() {
    //TODO remove global references

    var mt = {};
    mt._loaded = [];

    mt.url = function(name) {
        var base = kloudspeaker.settings["template-url"] || 'templates/';
        return kloudspeaker.helpers.noncachedUrl(kloudspeaker.resourceUrl(base + name));
    };

    mt.load = function(name, url) {
        var df = $.Deferred();
        if (mt._loaded.indexOf(name) >= 0) {
            return df.resolve();
        }

        $.get(url ? kloudspeaker.resourceUrl(url) : mt.url(name)).done(function(h) {
            mt._loaded.push(name);
            $("body").append(h);
            df.resolve();
        }).fail(function(f) {
            df.reject();
        });
        return df;
    };

    return mt;
});