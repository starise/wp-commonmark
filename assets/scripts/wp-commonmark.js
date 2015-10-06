(function($) {
  var app;
  app = window.markdownOnSaveApp = {
    on: function() {
      var a, i, len, ref, results;
      $('body').addClass('wcm-markdown');
      this.html.click();
      this.checkbox.attr('checked', true);
      this.buttonOn.show();
      ref = [this.buttonOff, this.html, this.visual, this.htmlButtons];
      results = [];
      for (i = 0, len = ref.length; i < len; i++) {
        a = ref[i];
        results.push(a.hide());
      }
      return results;
    },
    off: function() {
      var a, i, len, ref, results;
      $('body').removeClass('wcm-markdown');
      this.checkbox.attr('checked', false);
      this.buttonOn.hide();
      ref = [this.buttonOff, this.html, this.visual, this.htmlButtons];
      results = [];
      for (i = 0, len = ref.length; i < len; i++) {
        a = ref[i];
        results.push(a.show());
      }
      return results;
    },
    delay: function(ms, f) {
      return setTimeout(f, ms);
    },
    start: function() {
      var a, context;
      context = $('#wcm-markdown');
      context.detach().insertBefore('#submitdiv h3 span').show();
      this.buttonOn = $('img.markdown-on', context);
      this.buttonOff = $('img.markdown-off', context);
      this.checkbox = $('#wcm_using_markdown');
      this.html = $('#content-html');
      this.visual = $('#content-tmce');
      this.htmlButtonsString = ((function() {
        var i, len, ref, results;
        ref = ['strong', 'em', 'link', 'block', 'del', 'ins', 'img', 'ul', 'ol', 'li', 'code', 'close'];
        results = [];
        for (i = 0, len = ref.length; i < len; i++) {
          a = ref[i];
          results.push('#qt_content_' + a);
        }
        return results;
      })()).join(', ');
      this.htmlButtons = $(this.htmlButtonsString);
      this.events();
      return this.setFromCheckbox();
    },
    setFromCheckbox: function() {
      if (app.checkbox.is(':checked')) {
        return app.on();
      } else {
        return app.off();
      }
    },
    events: function() {
      $([this.buttonOn, this.buttonOff]).each(function() {
        return $(this).click(function(e) {
          e.stopPropagation();
          return app.checkbox.click();
        });
      });
      return this.checkbox.change(this.setFromCheckbox);
    }
  };
  return $(function() {
    return app.delay(0, function() {
      return app.start();
    });
  });
})(jQuery);
