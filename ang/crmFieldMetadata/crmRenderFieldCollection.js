(function (angular, $, _) {
  angular.module('crmFieldMetadata').directive('crmRenderFieldCollection', function() {
    return {
      restrict: 'A',
      scope: {
        collection: '=crmRenderFieldCollection',
        model: '=',
        prefix: "="
      },
      controller: ['$scope', '$element', '$sce', function crmRenderFieldCollectionController($scope, $element, $sce) {
        //let the scope know if the title should be displayed as a legend or an h3
        $scope.legend = ($element[0].nodeName === "FIELDSET");

        //Add a class to the containing element.
        $element.addClass("crmRenderFieldCollection-"+$scope.collection.name);

        var fieldList = [];
        _.each($scope.collection.fields, function(field, fieldKey) {
           fieldList.push({key: fieldKey, order: field.order});
        });
        $scope.fieldList = fieldList;

        $scope.preText = $sce.trustAsHtml($scope.collection.preText);
        $scope.postText = $sce.trustAsHtml($scope.collection.postText);
      }],
      templateUrl: '~/crmFieldMetadata/crmRenderFieldCollection.html'
    };
  });
})(angular, CRM.$, CRM._);