/** global: Craft */
/** global: Garnish */
/**
 * Craft Charts
 */

Craft.charts = {};

// ---------------------------------------------------------------------

/**
 * Class Craft.charts.DataTable
 */
Craft.charts.DataTable = Garnish.Base.extend(
{
    columns: null,
    rows: null,

    init: function(data) {
        columns = data.columns;
        rows = data.rows;

        rows.forEach($.proxy(function(d) {
            $.each(d, function(cellIndex, cell) {
                var column = columns[cellIndex];

                switch (column.type) {
                    case 'date':
                        var parseTime = d3.timeParse("%Y-%m-%d");
                        d[cellIndex] = parseTime(d[cellIndex]);
                        break;

                    case 'datetime':
                        var parseTime = d3.timeParse("%Y-%m-%d %H:00:00");
                        d[cellIndex] = parseTime(d[cellIndex]);
                        break;

                    case 'percent':
                        d[cellIndex] = d[cellIndex] / 100;
                        break;

                    case 'number':
                        d[cellIndex] = +d[cellIndex];
                        break;

                    default:
                        // do nothing
                }
            });

        }, this));

        this.columns = columns;
        this.rows = rows;
    }
});

// ---------------------------------------------------------------------

/**
 * Class Craft.charts.Tip
 */

Craft.charts.Tip = Garnish.Base.extend({
    $tip: null,

	init: function($container) {
		this.$container = $container;

		this.$tip = $('<div class="tooltip"></div>').appendTo(this.$container);

		this.hide();
	},

    setContent: function(html) {
		this.$tip.html(html);
    },

    setPosition: function(position) {
		this.$tip.css("left", position.left + "px");
		this.$tip.css("top", position.top + "px");
    },

	show: function() {
		this.$tip.css("display", 'block');
	},

	hide: function() {
		this.$tip.css("display", 'none');
	},
});

// ---------------------------------------------------------------------

/**
 * Class Craft.charts.BaseChart
 */
Craft.charts.BaseChart = Garnish.Base.extend(
{
    $container: null,
    $chart: null,

    chartBaseClass: 'cp-chart',
    dataTable: null,

    formatLocale: null,
    timeFormatLocale: null,
    orientation: null,

    svg: null,
    width: null,
    height: null,
    x: null,
    y: null,

    init: function(container, settings) {
        this.$container = container;

		this.setSettings(Craft.charts.BaseChart.defaults);
		this.setSettings(settings);

        var globalSettings = {
            formats: window.d3Formats,
            formatLocaleDefinition: window.d3FormatLocaleDefinition,
            timeFormatLocaleDefinition: window.d3TimeFormatLocaleDefinition
        };

		this.setSettings(globalSettings);

        d3.select(window).on('resize', $.proxy(function() {
            this.resize();
        }, this));
    },

    draw: function(dataTable, settings) {
        // Settings and chart attributes

		this.setSettings(settings);

		this.dataTable = dataTable;
		this.formatLocale = d3.formatLocale(this.settings.formatLocaleDefinition);
		this.timeFormatLocale = d3.timeFormatLocale(this.settings.timeFormatLocaleDefinition);
		this.orientation = this.settings.orientation;


		// Set (or reset) the chart element

		if (this.$chart) {
			this.$chart.remove();
		}

		var className = this.chartBaseClass;

		if (this.settings.chartClass) {
			className += ' ' + this.settings.chartClass;
		}

		this.$chart = $('<div class="' + className + '" />').appendTo(this.$container);
    },

    getTimeFormatter: function(timeFormatLocale, dataScale)
    {
		switch (dataScale) {
			case 'year':
				return timeFormatLocale.format('%Y');

			case 'month':
				return timeFormatLocale.format(this.settings.formats.shortDateFormats.month);

			case 'hour':
				return timeFormatLocale.format(this.settings.formats.shortDateFormats.day + " %H:00:00");

			default:
				return timeFormatLocale.format(this.settings.formats.shortDateFormats.day);
		}
    },
    
    getNumberFormatter: function(formatLocale, type)
    {
		switch (type) {
			case 'currency':
				return formatLocale.format(this.settings.formats.currencyFormat);

			case 'percent':
				return formatLocale.format(this.settings.formats.percentFormat);

			case 'time':
				return Craft.charts.utils.getDuration;

			default:
				return formatLocale.format(".2");
		}
    },

    resize: function() {
        this.draw(this.dataTable, this.settings);
    },

    onAfterDrawTicks: function() {
        // White border for ticks' text
        $('.tick', this.$chart).each(function(tickKey, tick) {
            var $tickText = $('text', tick);

            var $clone = $tickText.clone();
            $clone.appendTo(tick);

            $tickText.attr('stroke', '#ffffff');
            $tickText.attr('stroke-width', 3);
        });
    }
},
{
    defaults: {
        formatLocaleDefinition: null,
        timeFormatLocaleDefinition: null,
        formats: {
            shortDateFormats: {
                day: "%-m/%-d",
                month: "%-m/%y",
                year: "%Y"
            }
        },
        margin: {top: 25, right: 25, bottom: 25, left: 25},
        chartClass: null,
        colors: ["#0594D1", "#DE3800", "#FF9A00", "#009802", "#9B009B"],
        ticksStyles: {
            'fill': '#555',
            'font-size': '11px'
        }
    }
});

// ---------------------------------------------------------------------

/**
 * Class Craft.charts.Area
 */
Craft.charts.Area = Craft.charts.BaseChart.extend(
{
    tip: null,

    paddedX: null,
    paddedY: null,

    init: function(container, settings)
    {
        this.base(container, Craft.charts.Area.defaults);

		this.setSettings(settings);
    },

    draw: function(dataTable, settings) {

        this.base(dataTable, settings);

        if (this.tip) {
            this.tip = null;
        }

        this.width = this.$chart.width() - this.settings.margin.left - this.settings.margin.right;
        this.height = this.$chart.height() - this.settings.margin.top - this.settings.margin.bottom;


        // X & Y Scales & Domains

        this.x = d3.scaleTime().range([0, this.width]);
        this.y = d3.scaleLinear().range([this.height, 0]);
        this.x.domain(this.xDomain());
        this.y.domain(this.yDomain());


        // Append SVG to chart element

        var svg = {
            width: this.width + (this.settings.margin.left + this.settings.margin.right),
            height: this.height + (this.settings.margin.top + this.settings.margin.bottom),
            translateX: (this.orientation != 'rtl' ? (this.settings.margin.left) : (this.settings.margin.right)),
            translateY: this.settings.margin.top
        };

        this.svg = d3.select(this.$chart.get(0)).append("svg")
            .attr("width", svg.width)
            .attr("height", svg.height)
            .append("g")
            .attr("transform", "translate(" + svg.translateX + "," + svg.translateY + ")");


        // Draw elements

        this.drawGridlines();
        this.drawYTicks();


        // Draw padded elements

        var chartMargin = this.getChartMargin();
        this.paddedX = d3.scaleTime().range([chartMargin.left, (this.width - chartMargin.right)]);
        this.paddedY = d3.scaleLinear().range([this.height, 0]);
        this.paddedX.domain(this.xDomain());
        this.paddedY.domain(this.yDomain());


        // Draw

        this.drawXTicks();
        this.onAfterDrawTicks();
        this.drawAxes();
        this.drawChart();
        this.drawPlots();
        this.drawTipTriggers();
    },

    getChartMargin: function() {
        var left = 0;
        var right = 0;


        // calculate left based on widest Y tick's width

        var yTickMaxWidth = 0;

        $('.y .tick text:last', this.$chart).each(function(tickKey, tick) {
            var tickWidth = $(tick).get(0).getBoundingClientRect().width;

            if (tickWidth > yTickMaxWidth) {
                yTickMaxWidth = tickWidth;
            }
        });

        left = yTickMaxWidth + 14;

        return {
            left: (this.orientation != 'rtl' ? left : right),
            right: (this.orientation != 'rtl' ? right : left)
        };
    },

    drawChart: function() {
        var x = this.paddedX;
        var y = this.paddedY;

        // Line

        var line = d3.line()
            .x(function(d) {
                return x(d[0]);
            })
            .y(function(d) {
                return y(d[1]);
            });

        this.svg
            .append("g")
            .attr("class", "chart-line")
            .append("path")
            .datum(this.dataTable.rows)
            .style('fill', 'none')
            .style('stroke', this.settings.colors[0])
            .style('stroke-width', '3px')
            .attr("d", line);

        // Area
        var area = d3.area()
            .x(function(d) {
                return x(d[0]);
            })
            .y0(this.height)
            .y1(function(d) {
                return y(d[1]);
            });

        // Area
        this.svg
            .append("g")
            .attr("class", "chart-area")
            .append("path")
            .datum(this.dataTable.rows)
            .style('fill', this.settings.colors[0])
            .style('fill-opacity', '0.3')
            .attr("d", area);
    },

    drawAxes: function() {
        var x = d3.scaleTime().range([0, this.width]);
        var y = this.y;

        var xAxis = d3.axisBottom(x).ticks(0).tickSizeOuter(0);

        var xTranslateX = -0;
        var xTranslateY = this.height;

        this.svg.append("g")
            .attr("class", "x axis")
            .attr("transform", "translate(" + xTranslateX + "," + xTranslateY + ")")
            .call(xAxis);

        var chartMargin = this.getChartMargin();

        if (this.settings.axis.y.show) {
            if (this.orientation == 'rtl') {
                var yTranslateX = this.width - chartMargin.right;
                var yTranslateY = 0;

                var yAxis = d3.axisLeft(y).ticks(0);

                this.svg.append("g")
                    .attr("class", "y axis")
                    .attr("transform", "translate(" + yTranslateX + ", " + yTranslateY + ")")
                    .call(yAxis);
            }
            else {
                var yTranslateX = chartMargin.left;
                var yTranslateY = 0;

                var yAxis = d3.axisRight(y).ticks(0);

                this.svg.append("g")
                    .attr("class", "y axis")
                    .attr("transform", "translate(" + yTranslateX + ", " + yTranslateY + ")")
                    .call(yAxis);
            }
        }
    },

    drawYTicks: function() {
        var y = this.y;

        if (this.orientation == 'rtl') {
            var yAxis = d3.axisLeft(y)
                .tickFormat(this.yTickFormat())
                .tickValues(this.yTickValues())
                .ticks(this.yTicks());

            var translateX = this.width + 10;
            var translateY = 0;

            this.svg.append("g")
                .attr("class", "y ticks-axis")
                .attr("transform", "translate(" + translateX + ",0)")
                .style('fill', this.settings.ticksStyles['fill'])
                .style('font-size', this.settings.ticksStyles['font-size'])
                .call(yAxis);

            this.svg.selectAll('.y.ticks-axis text').style('text-anchor', 'start');
        }
        else {
            var yAxis = d3.axisRight(y)
                .tickFormat(this.yTickFormat())
                .tickValues(this.yTickValues())
                .ticks(this.yTicks());

            var translateX = -(10);
            var translateY = 0;

            this.svg.append("g")
                .style('fill', this.settings.ticksStyles['fill'])
                .style('font-size', this.settings.ticksStyles['font-size'])
                .attr("class", "y ticks-axis")
                .attr("transform", "translate(" + translateX + ", " + translateY + ")")
                .call(yAxis);
        }
    },

	xTickFormat: function() {
		return this.getTimeFormatter(this.timeFormatLocale, this.settings.dataScale);
	},

	yTickFormat: function() {
		return this.getNumberFormatter(this.formatLocale, this.dataTable.columns[1].type);
	},

    drawXTicks: function() {
        var x = this.paddedX;

        var xAxis = d3.axisBottom(x)
            .tickFormat(this.xTickFormat())
            .ticks(this.xTicks());

        this.svg.append("g")
            .attr("class", "x ticks-axis")
            .attr("transform", "translate(0," + this.height + ")")
            .style('fill', this.settings.ticksStyles['fill'])
            .style('font-size', this.settings.ticksStyles['font-size'])
            .call(xAxis);
    },

    drawGridlines: function() {
        var x = this.x;
        var y = this.y;

        if (this.settings.xAxisGridlines) {
            var xLineAxis = d3.axisBottom(x);

            // draw x lines
            this.svg.append("g")
                .attr("class", "x grid-line")
                .attr("transform", "translate(0," + this.height + ")")
                .call(xLineAxis
                    .tickSize(-this.height, 0, 0)
                    .tickFormat("")
                );
        }

        if (this.settings.yAxisGridlines) {
            var yLineAxis = d3.axisLeft(y);

            var translateX = 0;
            var translateY = 0;

            var innerTickSize = -(this.width);
            var tickSizeOuter = 0;

            this.svg.append("g")
                .attr("class", "y grid-line")
                .attr("transform", "translate(-" + translateX + " , " + translateY + ")")
                .call(yLineAxis
                    .tickSize(innerTickSize, tickSizeOuter)
                    .tickFormat("")
                    .tickValues(this.yTickValues())
                    .ticks(this.yTicks())
                );
        }
    },

    drawPlots: function() {
        var x = this.paddedX;
        var y = this.paddedY;

        if (this.settings.enablePlots) {
            this.svg.append('g')
                .attr("class", "plots")
                .selectAll("circle")
                .data(this.dataTable.rows)
                .enter()
                .append("circle")
                .style('fill', this.settings.colors[0])
                .attr("class", $.proxy(function(d, index) {
                    return 'plot plot-' + index;
                }, this))
                .attr("r", 4)
                .attr("cx", $.proxy(function(d) {
                    return x(d[0]);
                }, this))
                .attr("cy", $.proxy(function(d) {
                    return y(d[1]);
                }, this));
        }
    },

    expandPlot: function(index) {
        this.svg.select('.plot-' + index).attr("r", 5);
    },

    unexpandPlot: function(index) {
        this.svg.select('.plot-' + index).attr("r", 4);
    },

    xAxisTickInterval: function() {
        var chartMargin = this.getChartMargin();

        var tickSizeOuter = 6;
        var length = this.svg.select('.x path.domain').node().getTotalLength() - chartMargin.left - chartMargin.right - tickSizeOuter * 2;
        var interval = length / (this.dataTable.rows.length - 1);

        return interval;
    },

    drawTipTriggers: function() {
        var x = this.paddedX;

        if (this.settings.enableTips) {

            if (!this.tip) {
                this.tip = new Craft.charts.Tip(this.$chart);
            }

            var tipTriggerWidth = Math.max(0, this.xAxisTickInterval());

            this.svg.append('g')
                .attr("class", "tip-triggers")
                .selectAll("rect")
                .data(this.dataTable.rows)
                .enter().append("rect")
                .attr("class", "tip-trigger")
                .style('fill', 'transparent')
                .style('fill-opacity', '1')
                .attr("width", tipTriggerWidth)
                .attr("height", this.height)
                .attr("x", $.proxy(function(d) {
                    return x(d[0]) - tipTriggerWidth / 2;
                }, this))
                .on("mouseover", $.proxy(function(d, index) {
                    this.expandPlot(index);

                    var content = this.getTipContent(d);
                    this.tip.setContent(content);

                    var tipPosition = this.getTipPosition(this.tip.$tip, d);
                    this.tip.setPosition(tipPosition);

                    this.tip.show();

                }, this))
                .on("mouseout", $.proxy(function(d, index) {
                    this.unexpandPlot(index);
                    this.tip.hide();
                }, this));
        }

        // Apply shadow filter
        Craft.charts.utils.applyShadowFilter('drop-shadow', this.svg);
    },

	getTipContent: function(d) {
		var $content = $('<div />');
		var $xValue = $('<div class="x-value" />').appendTo($content);
		var $yValue = $('<div class="y-value" />').appendTo($content);

		$xValue.html(this.xTickFormat()(d[0]));
		$yValue.html(this.yTickFormat()(d[1]));

		return $content.get(0);
	},

    getTipPosition: function($tip, d) {
        var x = this.paddedX;
        var y = this.paddedY;

        var chartMargin = this.getChartMargin();

        var offset = 24;
        var top = (y(d[1]) - $tip.height() / 2);
        var left;

        if (this.orientation != 'rtl') {
            left = (x(d[0]) + this.settings.margin.left + offset);

            var calcLeft = (this.$chart.offset().left + left + $tip.width());
            var maxLeft = this.$chart.offset().left + this.$chart.width() - offset;

            if (calcLeft > maxLeft) {
                left = x(d[0]) - ($tip.width() + offset);
            }
        }
        else {
            left = (x(d[0]) - ($tip.width() + this.settings.margin.left + offset));
        }

        if (left < 0) {
            left = (x(d[0]) + this.settings.margin.left + offset);
        }

        return {
            top: top,
            left: left,
        };
    },

    xDomain: function() {
        var min = d3.min(this.dataTable.rows, function(d) {
            return d[0];
        });
        var max = d3.max(this.dataTable.rows, function(d) {
            return d[0];
        });

        if (this.orientation == 'rtl') {
            return [max, min];
        }
        else {
            return [min, max];
        }
    },

    xTicks: function() {
        return 3;
    },

    yAxisMaxValue: function() {
        return d3.max(this.dataTable.rows, function(d) {
            return d[1];
        });
    },

    yDomain: function() {
        var yDomainMax = $.proxy(function() {
            return this.yAxisMaxValue();

        }, this);

        return [0, yDomainMax()];
    },

    yTicks: function() {
        return 2;
    },

    yTickValues: function() {
        return [this.yAxisMaxValue() / 2, this.yAxisMaxValue()];
    },
},
{
    defaults: {
        chartClass: 'area',
        enablePlots: true,
        enableTips: true,
        xAxisGridlines: false,
        yAxisGridlines: true,
        axis: {
            y: {
                show: false
            }
        }
    }
});

// ---------------------------------------------------------------------

/**
 * Class Craft.charts.Utils
 */
Craft.charts.utils = {

    getDuration: function(value) {
        var sec_num = parseInt(value, 10);
        var hours = Math.floor(sec_num / 3600);
        var minutes = Math.floor((sec_num - (hours * 3600)) / 60);
        var seconds = sec_num - (hours * 3600) - (minutes * 60);

        if (hours < 10) {
            hours = "0" + hours;
        }

        if (minutes < 10) {
            minutes = "0" + minutes;
        }

        if (seconds < 10) {
            seconds = "0" + seconds;
        }

        var time = hours + ':' + minutes + ':' + seconds;

        return time;
    },

    /**
     * arrayToDataTable
     */
    arrayToDataTable: function(twoDArray) {

        var data = {
            columns: [],
            rows: []
        };

        $.each(twoDArray, function(k, v) {
            if (k == 0) {
                // first row is column definition

                data.columns = [];

                $.each(v, function(k2, v2) {

                    // guess column type from first row
                    var columnType = typeof(twoDArray[(k + 1)][k2]);

                    var column = {
                        name: v2,
                        type: columnType,
                    };

                    data.columns.push(column);
                });
            }
            else {
                var row = [];

                $.each(v, function(k2, v2) {
                    var cell = v2;

                    row.push(cell);
                });

                data.rows.push(row);
            }
        });

        var dataTable = new Craft.charts.DataTable(data);

        return dataTable;
    },

    applyShadowFilter: function(id, svg) {
        // filters go in defs element
        var defs = svg.append("defs");

        // create filter with id #{id}
        // height=130% so that the shadow is not clipped
        var filter = defs.append("filter")
            .attr("id", id)
            .attr("width", "200%")
            .attr("height", "200%")
            .attr("x", "-50%")
            .attr("y", "-50%");

        // SourceAlpha refers to opacity of graphic that this filter will be applied to
        // convolve that with a Gaussian with standard deviation 3 and store result
        // in blur
        filter.append("feGaussianBlur")
            .attr("in", "SourceAlpha")
            .attr("stdDeviation", 1)
            .attr("result", "blur");

        // translate output of Gaussian blur to the right and downwards with 2px
        // store result in offsetBlur
        filter.append("feOffset")
            .attr("in", "blur")
            .attr("dx", 0)
            .attr("dy", 0)
            .attr("result", "offsetBlur");

        // overlay original SourceGraphic over translated blurred opacity by using
        // feMerge filter. Order of specifying inputs is important!
        var feMerge = filter.append("feMerge");

        feMerge.append("feMergeNode")
            .attr("in", "offsetBlur");
        feMerge.append("feMergeNode")
            .attr("in", "SourceGraphic");
    }
};

